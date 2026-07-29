<?php

namespace App\Services\Booking;

use App\Enums\CancellationEvent;
use App\Models\Reservation;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Decides how a paid booking's money is split when it ends without being played.
 *
 * THE INVARIANT: user + owner === amount_paid_minor, exactly, in every branch.
 *
 * Percentages are applied with integer division and the remainder is handed to
 * the customer. That is not arbitrary generosity -- it means the two halves
 * always reconstruct the total with no paisa created or destroyed, which is the
 * property the ledger depends on. Rounding each side independently would leave
 * a stray paisa unaccounted for on roughly half of all odd-numbered amounts.
 *
 * The tiers come from the reservation's OWN snapshot, never from live config.
 * A dispute is argued against the policy the customer agreed to.
 */
class RefundResolver
{
    /**
     * @return array{user_minor: int, owner_minor: int, refund_percent: int}
     */
    public function resolve(Reservation $reservation, CancellationEvent $event, ?Carbon $at = null): array
    {
        $paid = (int) ($reservation->amount_paid_minor ?? 0);

        if ($paid <= 0) {
            return ['user_minor' => 0, 'owner_minor' => 0, 'refund_percent' => 0];
        }

        $percent = $this->refundPercent($reservation, $event, $at ?? Carbon::now());

        // Integer division, remainder to the customer. See the class note.
        $userMinor = intdiv($paid * $percent, 100);

        return [
            'user_minor' => $userMinor,
            'owner_minor' => $paid - $userMinor,
            'refund_percent' => $percent,
        ];
    }

    /**
     * The percentage the customer gets back.
     *
     * Owner and Admin cancellations short-circuit at 100% before the tiers are
     * consulted at all -- the customer did nothing wrong and must not be
     * penalised for when the venue happened to pull out.
     */
    public function refundPercent(Reservation $reservation, CancellationEvent $event, ?Carbon $at = null): int
    {
        if (! $event->usesTiers()) {
            return match ($event) {
                CancellationEvent::OwnerCancelled, CancellationEvent::AdminCancelled => 100,
                // The venue held the slot and turned away other customers.
                CancellationEvent::NoShow => 0,
                default => 0,
            };
        }

        $hoursBefore = $this->hoursUntilStart($reservation, $at ?? Carbon::now());

        foreach ($this->tiersFor($reservation) as $tier) {
            if ($hoursBefore >= (float) $tier['min_hours_before']) {
                return (int) $tier['refund_percent'];
            }
        }

        // No tier matched, which means the table has no zero-hour floor. Treat
        // it as a full forfeit rather than guessing in the customer's favour --
        // a misconfigured policy must not become a refund exploit.
        return 0;
    }

    /**
     * The policy to record on a reservation at booking time.
     *
     * Called by ReservationService::request() and stored verbatim, so this is
     * the only moment live config is read for a given booking.
     *
     * @return array<int, array{min_hours_before: int|float, refund_percent: int}>
     */
    public function snapshotTiers(): array
    {
        return array_values(config('wallet.refund_tiers', []));
    }

    /**
     * The tiers this reservation was booked under.
     *
     * Falls back to live config only for rows that predate the snapshot column
     * -- there is nothing better available for those, and the alternative is
     * refusing to cancel them at all.
     *
     * @return array<int, array{min_hours_before: int|float, refund_percent: int}>
     */
    public function tiersFor(Reservation $reservation): array
    {
        $snapshot = $reservation->refund_policy_snapshot;

        return filled($snapshot) ? $snapshot : $this->snapshotTiers();
    }

    /**
     * Hours between now and the slot start. Negative once it has begun, which
     * correctly falls through every tier to a full forfeit.
     */
    private function hoursUntilStart(Reservation $reservation, Carbon $at): float
    {
        return $at->diffInMinutes($reservation->start_datetime, false) / 60;
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    */

    /**
     * The policy as prose, for the booking form and the cancel dialog.
     *
     * Customers should be able to read the terms before paying, not discover
     * them at the moment of cancelling.
     *
     * @return array<int, string>
     */
    public function describe(Reservation|array|null $subject = null): array
    {
        $tiers = $subject instanceof Reservation ? $this->tiersFor($subject) : ($subject ?? $this->snapshotTiers());

        $lines = [];
        $previous = null;

        foreach ($tiers as $tier) {
            $hours = (float) $tier['min_hours_before'];
            $percent = (int) $tier['refund_percent'];

            $window = match (true) {
                $previous === null => 'More than '.Money::duration((int) ($hours * 60)).' before',
                $hours <= 0 => 'Less than '.Money::duration((int) ($previous * 60)).' before',
                default => Money::duration((int) ($hours * 60)).' to '.Money::duration((int) ($previous * 60)).' before',
            };

            $lines[] = $window.': '.($percent === 0 ? 'no refund' : $percent.'% refunded');
            $previous = $hours;
        }

        return $lines;
    }
}
