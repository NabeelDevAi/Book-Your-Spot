<?php

namespace App\Services\Booking;

use App\Models\Spot;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * Turns a duration into a price, and into the snapshot fields stored on the
 * reservation.
 *
 * SRS 9.10 requires the price agreed at booking time to survive any later
 * change to the Spot. That is why this returns a snapshot rather than a bare
 * total: the rate and billing unit travel with the reservation, so a confirmed
 * booking can always be explained ("Rs. 100 per 10 min x 6") even years later,
 * when the Spot's live pricing has moved on.
 *
 * Every calculation takes the booking's start date, because a Spot may bill a
 * different rate on weekends and holidays (Spot::rateFor()) -- the rate that
 * matters is the one on the day the booking actually happens, not today.
 */
class PricingCalculator
{
    /** @return array{price_amount_snapshot: string, price_unit_minutes_snapshot: int, total_price: float} */
    public function snapshotFor(Spot $spot, CarbonInterface $date, int $durationMinutes): array
    {
        return [
            'price_amount_snapshot' => $spot->rateFor($date),
            'price_unit_minutes_snapshot' => $spot->price_unit_minutes,
            'total_price' => $this->total($spot, $date, $durationMinutes),
        ];
    }

    /** Whole billing units, rounded up -- never under-charge on a partial unit. */
    public function total(Spot $spot, CarbonInterface $date, int $durationMinutes): float
    {
        return round($this->units($spot, $durationMinutes) * $spot->rateFor($date), 2);
    }

    public function units(Spot $spot, int $durationMinutes): int
    {
        return (int) ceil($durationMinutes / $spot->price_unit_minutes);
    }

    /** "Rs. 100 × 6 (10 min blocks) = Rs. 600" -- the breakdown shown on the booking form. */
    public function explain(Spot $spot, CarbonInterface $date, int $durationMinutes): string
    {
        $units = $this->units($spot, $durationMinutes);
        $rate = $spot->rateFor($date);

        return sprintf(
            '%s%s × %d (%s %s) = %s',
            Money::pkr($rate),
            $spot->isWeekendRateDay($date) && $spot->hasDistinctWeekendRate() ? ' (weekend rate)' : '',
            $units,
            Money::duration($spot->price_unit_minutes),
            $units === 1 ? 'block' : 'blocks',
            Money::pkr(round($units * $rate, 2)),
        );
    }
}
