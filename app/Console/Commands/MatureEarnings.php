<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\Booking\SettlementService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Releases an Owner's earnings into their withdrawable balance.
 *
 * This command is the whole reason a clawback is never needed. Earnings sit in
 * `pending` from the moment the Owner approves until the booking has finished
 * AND a dispute window has elapsed; only then do they become money the Owner
 * can withdraw. Refunds always resolve against `pending`, so by construction
 * they can never need to reach into an account that has already been paid out.
 *
 * Shortening `wallet.maturity_hours` to nothing would quietly undo that
 * guarantee, which is why it is a config value with a comment rather than a
 * literal buried in this file.
 *
 * A no-show matures on the same schedule -- the venue held the slot and turned
 * other customers away, so they are owed for it.
 */
class MatureEarnings extends Command
{
    protected $signature = 'wallet:mature';

    protected $description = 'Release matured booking earnings into owners\' withdrawable balances';

    public function handle(SettlementService $settlement): int
    {
        $cutoff = now()->subHours((int) config('wallet.maturity_hours'));

        $due = Reservation::query()
            ->whereNull('earnings_matured_at')
            // Terminal states in which the Owner is owed something. A cancelled
            // booking qualifies too: a late cancellation leaves the venue with
            // a share, and that share matures like any other.
            ->whereIn('status', [
                ReservationStatus::Completed->value,
                ReservationStatus::NoShow->value,
                ReservationStatus::Cancelled->value,
            ])
            ->where('end_datetime', '<=', $cutoff)
            ->orderBy('id')
            ->get();

        if ($due->isEmpty()) {
            return self::SUCCESS;
        }

        $released = 0;

        foreach ($due as $reservation) {
            try {
                // Its own transaction per reservation: one owner's unusual
                // ledger state must not roll back everybody else's earnings.
                $moved = $settlement->inTransaction(
                    fn () => $settlement->mature($reservation),
                );

                if ($moved) {
                    $released++;
                }
            } catch (Throwable $e) {
                report($e);
                $this->error("Could not mature reservation #{$reservation->id}: {$e->getMessage()}");
            }
        }

        $this->info("Matured {$due->count()} reservation(s), {$released} with earnings to release.");

        return self::SUCCESS;
    }
}
