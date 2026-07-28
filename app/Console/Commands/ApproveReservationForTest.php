<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Models\User;
use App\Services\Booking\ReservationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Entry point for the concurrency test's worker processes.
 *
 * It exists so each worker can call the real ReservationService::approve() in
 * its own OS process, with its own database connection, against the real
 * server -- which is the only setting in which the row lock can be observed.
 *
 * Refuses to run in production.
 *
 * @see \Tests\Feature\Booking\ConcurrentApprovalTest
 */
class ApproveReservationForTest extends Command
{
    protected $signature = 'reservations:approve-for-test
        {reservation : Reservation id}
        {actor : Approving user id}
        {--start-at= : Unix timestamp (float) to busy-wait until, so workers start together}
        {--lock-timeout= : innodb_lock_wait_timeout for this session, in seconds}
        {--hold= : Seconds to pause inside the transaction after the spot row is read}';

    protected $description = 'Internal: approve a reservation (concurrency test harness only)';

    public function handle(ReservationService $reservations): int
    {
        if (app()->environment('production')) {
            $this->error('Not available in production.');

            return self::FAILURE;
        }

        if ($timeout = $this->option('lock-timeout')) {
            DB::statement('SET SESSION innodb_lock_wait_timeout = '.(int) $timeout);
        }

        $reservation = Reservation::find($this->argument('reservation'));
        $actor = User::find($this->argument('actor'));

        if (! $reservation || ! $actor) {
            $this->error('Reservation or actor not found.');

            return self::FAILURE;
        }

        if ($hold = $this->option('hold')) {
            $this->holdAfterReadingSpot((int) $hold);
        }

        // Synchronise here rather than at process launch: booting Laravel takes
        // tens of milliseconds and varies per process, which is far more jitter
        // than the race window being tested.
        if ($startAt = $this->option('start-at')) {
            $this->waitUntil((float) $startAt);
        }

        try {
            $reservations->approve($reservation, $actor);
            $this->line('confirmed');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            // Losing the race is the expected outcome for all but one worker.
            $this->line('refused: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Freeze this worker inside approve()'s transaction, right after it reads
     * the spot row, so a second worker is guaranteed to collide with it.
     *
     * Hooked via the query log rather than a parameter on the service, so the
     * production code path is exactly the one under test. It deliberately keys
     * on any select against `spots` -- not just a locking one -- because the
     * whole point is to behave identically whether or not `for update` is
     * present. Keying on `for update` would make the test silently stop
     * exercising the race the moment the lock was removed.
     */
    private function holdAfterReadingSpot(int $seconds): void
    {
        $held = false;

        DB::listen(function ($query) use ($seconds, &$held) {
            if ($held || ! str_contains(strtolower($query->sql), 'from `spots`')) {
                return;
            }

            $held = true;
            sleep($seconds);
        });
    }

    private function waitUntil(float $target): void
    {
        $remaining = $target - microtime(true);

        if ($remaining > 0.002) {
            usleep((int) (($remaining - 0.002) * 1_000_000));
        }

        // Spin out the last couple of milliseconds for a tight start.
        while (microtime(true) < $target) {
            // busy-wait
        }
    }
}
