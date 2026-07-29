<?php

namespace Tests\Feature\Booking;

use App\Enums\RejectionReason;
use App\Enums\ReservationStatus;
use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\User;
use App\Support\OperatingHours;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Success Criterion #3: no double-booking under concurrent load (NFR-1).
 *
 * MySQL has no exclusion constraint, so the guarantee rests entirely on
 * ReservationService::approve() taking `SELECT ... FOR UPDATE` on the SPOT row.
 * These tests exist to prove that lock is real and doing work.
 *
 * `two_overlapping_approvals_cannot_both_confirm` is the test that matters, and
 * it took three attempts to make it honest:
 *
 *   1. Racing N workers and counting confirmed rows PASSED with the lock
 *      removed -- the losers simply started after the winner had committed.
 *   2. Having the test hold the lock and asserting a worker blocked also PASSED
 *      without it, because reservations.spot_id is a foreign key and InnoDB
 *      takes a shared lock on the parent spot row for the UPDATE, which
 *      conflicts with the test's exclusive lock either way. Two SHARED locks
 *      are compatible with each other, so the FK does not prevent a double
 *      booking -- it only defeated the test.
 *   3. What works: freeze one worker inside its transaction just after it reads
 *      the spot row, race a second at it, and assert on what each worker
 *      REPORTED. Without the lock both report success and both notify their
 *      customer, even though the surviving rows can still look correct because
 *      the last writer's auto-reject sweep tidies up behind it. A customer told
 *      "confirmed" and then silently rejected is the real harm, and no row count
 *      can see it.
 *
 * Workers are separate OS processes, not pcntl forks. A fork inherits the
 * parent's MySQL socket, and the first thing a child does with it -- purge,
 * reconnect, or simply exit -- sends COM_QUIT and takes the parent's connection
 * down with it.
 *
 * RefreshDatabase is deliberately not used: it wraps each test in a transaction
 * the workers cannot see.
 */
class ConcurrentApprovalTest extends TestCase
{
    private array $createdUserIds = [];

    /** How many workers reported a successful approval in the last race. */
    private int $winners = 0;

    protected function tearDown(): void
    {
        DB::table('audit_logs')->delete();
        DB::table('notifications')->delete();
        // Wallet rows first: holds and ledger entries reference reservations
        // and wallets, and wallet_transactions restricts deletion of its wallet.
        DB::table('wallet_holds')->delete();
        DB::table('wallet_transactions')->delete();
        DB::table('wallets')->delete();
        DB::table('reservations')->delete();
        DB::table('spot_blocks')->delete();
        DB::table('spots')->delete();
        DB::table('business_games')->delete();
        DB::table('businesses')->delete();
        DB::table('games')->delete();
        DB::table('users')->whereIn('id', $this->createdUserIds)->delete();

        parent::tearDown();
    }

    /** @return array{spot: Spot, owner: User, reservations: array<int>} */
    private function scenario(int $competitors, bool $distinctSpots = false): array
    {
        $owner = User::factory()->owner()->create();
        $this->createdUserIds[] = $owner->id;

        $business = Business::factory()->active()->create([
            'owner_id' => $owner->id,
            'operating_hours' => OperatingHours::everyDay('00:00', '23:59'),
        ]);
        $businessGame = BusinessGame::factory()->create(['business_id' => $business->id]);

        $spot = Spot::factory()->create([
            'business_game_id' => $businessGame->id,
            'business_id' => $business->id,
        ]);

        $start = Carbon::now()->addDays(2)->setTime(19, 0);
        $ids = [];

        for ($i = 0; $i < $competitors; $i++) {
            $customer = User::factory()->create();
            $this->createdUserIds[] = $customer->id;

            $target = $distinctSpots
                ? Spot::factory()->create([
                    'business_game_id' => $businessGame->id,
                    'business_id' => $business->id,
                    'name' => "Table {$i}",
                ])
                : $spot;

            $ids[] = Reservation::factory()->forSpot($target)->at($start, 60)
                ->create(['user_id' => $customer->id])->id;
        }

        return ['spot' => $spot, 'owner' => $owner, 'reservations' => $ids];
    }

    /** A worker process pointed at the same test database. */
    private function worker(int $reservationId, int $ownerId, array $options = []): Process
    {
        $command = [
            PHP_BINARY, 'artisan', 'reservations:approve-for-test',
            (string) $reservationId, (string) $ownerId,
        ];

        foreach ($options as $key => $value) {
            $command[] = "--{$key}={$value}";
        }

        return new Process($command, base_path(), [
            // The worker boots from .env, which points at the development
            // database -- redirect it at the test schema.
            'APP_ENV' => 'testing',
            'DB_DATABASE' => config('database.connections.mysql.database'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The deterministic proof
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function two_overlapping_approvals_cannot_both_confirm(): void
    {
        // The discriminating test. Worker A is frozen inside its transaction
        // immediately after reading the spot row; worker B then attempts the
        // same slot while A still holds whatever locks approve() took.
        //
        //   With the lock:    B blocks on A's exclusive lock, and once A commits
        //                     B sees the confirmed row and refuses. One winner.
        //   Without it:       B's plain read does not block, and its MVCC
        //                     snapshot predates A's commit, so B sees a free
        //                     slot and confirms too. Two winners -> failure.
        //
        // An earlier version had the TEST hold the lock and asserted the worker
        // blocked. That passed even with the lock removed, because
        // reservations.spot_id has a foreign key and InnoDB takes a shared lock
        // on the parent spot row for the UPDATE -- which conflicts with the
        // test's exclusive lock regardless. Two shared locks are compatible with
        // each other, so the FK alone does NOT prevent a double booking; only a
        // real race between two approvals can tell the difference.
        ['owner' => $owner, 'reservations' => $ids] = $this->scenario(2);

        $holder = $this->worker($ids[0], $owner->id, ['hold' => 4]);
        $holder->start();

        // Let A get inside its transaction and take the lock.
        usleep(1_500_000);

        $challenger = $this->worker($ids[1], $owner->id, ['lock-timeout' => 10]);
        $challenger->run();

        $holder->wait();

        // Assert on what each worker BELIEVED it did, not just on the rows left
        // behind. Without the lock both workers confirm and both notify their
        // customer; the final table can still end up with a single confirmed row
        // purely because the last writer's auto-reject sweep tidies up after it.
        // A customer who was told "confirmed" and then silently rejected is the
        // actual harm, and it is invisible to a row count.
        $outcomes = [$holder->getOutput(), $challenger->getOutput()];
        $succeeded = count(array_filter($outcomes, fn ($o) => str_contains($o, 'confirmed')));

        $this->assertSame(
            1,
            $succeeded,
            "Both approvals succeeded — each customer was told their booking was confirmed.\n"
            ."Holder: {$holder->getOutput()}\nChallenger: {$challenger->getOutput()}"
        );

        $this->assertSame(
            1,
            Reservation::whereIn('id', $ids)->where('status', ReservationStatus::Confirmed)->count(),
            'Exactly one reservation may remain confirmed.'
        );
    }

    #[Test]
    public function an_approval_on_another_spot_is_not_blocked(): void
    {
        // The lock must serialise per spot, not globally. A global lock would
        // stop a busy venue approving two bookings at once.
        ['owner' => $owner, 'reservations' => $ids] = $this->scenario(2, distinctSpots: true);

        $holder = $this->worker($ids[0], $owner->id, ['hold' => 4]);
        $holder->start();

        usleep(1_500_000);

        $other = $this->worker($ids[1], $owner->id, ['lock-timeout' => 10]);
        $other->run();

        $holder->wait();

        $this->assertSame(
            2,
            Reservation::whereIn('id', $ids)->where('status', ReservationStatus::Confirmed)->count(),
            "Approvals on different spots must not block one another.\n"
            ."Output: {$other->getOutput()}"
        );
    }

    /*
    |--------------------------------------------------------------------------
    | End-to-end race under real load
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function a_real_race_between_eight_workers_produces_exactly_one_winner(): void
    {
        ['owner' => $owner, 'reservations' => $ids] = $this->scenario(8);

        $this->race($ids, $owner->id);

        $reservations = Reservation::whereIn('id', $ids)->get();

        $this->assertSame(
            1,
            $this->winners,
            "More than one worker reported a successful approval — each of those "
            .'customers was told their booking was confirmed.'
        );

        $this->assertCount(
            1,
            $reservations->where('status', ReservationStatus::Confirmed),
            'Exactly one reservation may be confirmed — more than one is a double-booking.'
        );

        // A loser left pending would sit in the owner's queue forever against a
        // slot that has already been sold.
        $this->assertCount(
            0,
            $reservations->where('status', ReservationStatus::Pending),
            'Every loser must be resolved, not left hanging.'
        );

        foreach ($reservations->where('status', ReservationStatus::Rejected) as $loser) {
            $this->assertSame(
                RejectionReason::SlotTaken,
                $loser->rejection_reason_code,
                'Losers are told they lost a race, not that the venue declined them.'
            );
        }
    }

    #[Test]
    public function concurrent_approvals_on_different_spots_all_succeed(): void
    {
        ['owner' => $owner, 'reservations' => $ids] = $this->scenario(4, distinctSpots: true);

        $this->race($ids, $owner->id);

        $this->assertSame(
            4,
            Reservation::whereIn('id', $ids)->where('status', ReservationStatus::Confirmed)->count(),
            'Approvals on different spots must not block one another.'
        );
    }

    /**
     * Launch every worker, then release them all at a shared timestamp so they
     * collide rather than queue.
     */
    private function race(array $reservationIds, int $ownerId): void
    {
        // Generous enough for every worker to finish booting Laravel before the
        // barrier fires; the barrier is what makes the start tight, not this.
        $startAt = microtime(true) + 3.0;

        $workers = [];

        foreach ($reservationIds as $id) {
            $worker = $this->worker($id, $ownerId, [
                'start-at' => number_format($startAt, 4, '.', ''),
            ]);
            $worker->start();
            $workers[] = $worker;
        }

        foreach ($workers as $worker) {
            $worker->wait();
        }

        $this->winners = count(array_filter(
            $workers,
            fn (Process $w) => str_contains($w->getOutput(), 'confirmed'),
        ));
    }
}
