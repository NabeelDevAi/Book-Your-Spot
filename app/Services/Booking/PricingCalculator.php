<?php

namespace App\Services\Booking;

use App\Models\Spot;
use App\Support\Money;

/**
 * Turns a duration into a price, and into the snapshot fields stored on the
 * reservation.
 *
 * SRS 9.10 requires the price agreed at booking time to survive any later
 * change to the Spot. That is why this returns a snapshot rather than a bare
 * total: the rate and billing unit travel with the reservation, so a confirmed
 * booking can always be explained ("Rs. 100 per 10 min x 6") even years later,
 * when the Spot's live pricing has moved on.
 */
class PricingCalculator
{
    /** @return array{price_amount_snapshot: string, price_unit_minutes_snapshot: int, total_price: float} */
    public function snapshotFor(Spot $spot, int $durationMinutes): array
    {
        return [
            'price_amount_snapshot' => $spot->price_amount,
            'price_unit_minutes_snapshot' => $spot->price_unit_minutes,
            'total_price' => $this->total($spot, $durationMinutes),
        ];
    }

    /** Whole billing units, rounded up -- never under-charge on a partial unit. */
    public function total(Spot $spot, int $durationMinutes): float
    {
        return round($this->units($spot, $durationMinutes) * (float) $spot->price_amount, 2);
    }

    /**
     * The same total in paisa, computed without floats.
     *
     * The rate is converted to an integer first and multiplied by an integer
     * unit count, so no rounding occurs at any point. `total()` above rounds a
     * float product and is fine for display, but the wallet ledger has to sum
     * exactly -- this is the figure that gets held, captured and refunded.
     */
    public function totalMinor(Spot $spot, int $durationMinutes): int
    {
        return Money::toMinor($spot->price_amount) * $this->units($spot, $durationMinutes);
    }

    public function units(Spot $spot, int $durationMinutes): int
    {
        return (int) ceil($durationMinutes / $spot->price_unit_minutes);
    }

    /** "Rs. 100 × 6 (10 min blocks) = Rs. 600" -- the breakdown shown on the booking form. */
    public function explain(Spot $spot, int $durationMinutes): string
    {
        $units = $this->units($spot, $durationMinutes);

        return sprintf(
            '%s × %d (%s %s) = %s',
            Money::pkr($spot->price_amount),
            $units,
            Money::duration($spot->price_unit_minutes),
            $units === 1 ? 'block' : 'blocks',
            Money::pkr($this->total($spot, $durationMinutes)),
        );
    }
}
