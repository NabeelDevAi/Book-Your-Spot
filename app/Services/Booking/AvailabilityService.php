<?php

namespace App\Services\Booking;

use App\Models\Reservation;
use App\Models\Spot;
use App\Models\SpotBlock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Works out when a Spot is actually free.
 *
 * Availability is computed on read rather than stored as pre-generated slot
 * rows. A snooker table billed in 10-minute blocks, open 12 hours a day, is 72
 * rows per table per day -- and every one of them would need regenerating
 * whenever the owner edits their hours or pricing. Computing from the
 * (small) set of reservations and blocks is both cheaper and always correct.
 *
 * Two things drive everything here:
 *
 *  - Only `confirmed` reservations occupy a spot. Multiple customers may hold
 *    `pending` requests on the same slot; the first approval wins.
 *  - Intervals are half-open, [start, end). A booking ending at 18:00 and
 *    another starting at 18:00 do not collide, which is what makes
 *    back-to-back slots sellable at all.
 */
class AvailabilityService
{
    /**
     * Opening windows for a date, as concrete datetimes.
     *
     * A window whose close time is earlier than its open time runs past
     * midnight, so the previous day's window can spill into this one. Both are
     * considered, or a 1 AM booking on a venue open "16:00–02:00" would look
     * like it falls outside opening hours.
     *
     * @return list<array{start: Carbon, end: Carbon}>
     */
    public function openWindows(Spot $spot, Carbon $date): array
    {
        $hours = $spot->effectiveHours();
        $day = $date->copy()->startOfDay();

        $windows = array_merge(
            $hours->windowsFor($day->copy()->subDay()),
            $hours->windowsFor($day),
        );

        // Keep anything that touches this calendar day at all.
        $dayEnd = $day->copy()->addDay();

        return array_values(array_filter(
            $windows,
            fn (array $w) => $w['end']->greaterThan($day) && $w['start']->lessThan($dayEnd),
        ));
    }

    /**
     * Occupied intervals in a range: confirmed bookings plus owner blocks.
     *
     * Each interval carries a `type` -- `booking` or `block` -- so the owner's
     * occupancy view can show downtime they created themselves differently from
     * time a customer actually paid for. Availability maths ignores the
     * distinction; both equally make the spot unbookable.
     *
     * @param  int|null  $ignoreReservationId  exclude one reservation, so a
     *                                         booking never conflicts with itself
     * @return list<array{start: Carbon, end: Carbon, type: string}>
     */
    public function busyIntervals(Spot $spot, Carbon $from, Carbon $to, ?int $ignoreReservationId = null): array
    {
        return $this->busyIntervalsForMany([$spot->id], $from, $to, $ignoreReservationId)[$spot->id] ?? [];
    }

    /**
     * The same thing for a set of spots, in two queries rather than two per
     * spot. The venue page asks about every spot at once, so doing this one
     * spot at a time turns a page into 2N queries.
     *
     * @param  list<int>  $spotIds
     * @return array<int, list<array{start: Carbon, end: Carbon, type: string}>>
     */
    public function busyIntervalsForMany(array $spotIds, Carbon $from, Carbon $to, ?int $ignoreReservationId = null): array
    {
        if ($spotIds === []) {
            return [];
        }

        $reservations = Reservation::query()
            ->whereIn('spot_id', $spotIds)
            ->blocking()
            ->overlapping($from, $to)
            ->when($ignoreReservationId, fn ($q) => $q->whereKeyNot($ignoreReservationId))
            ->get(['spot_id', 'start_datetime', 'end_datetime'])
            ->map(fn ($row) => [
                'spot_id' => $row->spot_id,
                'start' => $row->start_datetime,
                'end' => $row->end_datetime,
                'type' => 'booking',
            ]);

        $blocks = SpotBlock::query()
            ->whereIn('spot_id', $spotIds)
            ->overlapping($from, $to)
            ->get(['spot_id', 'start_datetime', 'end_datetime'])
            ->map(fn ($row) => [
                'spot_id' => $row->spot_id,
                'start' => $row->start_datetime,
                'end' => $row->end_datetime,
                'type' => 'block',
            ]);

        $grouped = $reservations->concat($blocks)
            ->sortBy(fn (array $i) => $i['start']->getTimestamp())
            ->groupBy('spot_id');

        $bySpot = [];

        foreach ($spotIds as $id) {
            $bySpot[$id] = $grouped->get($id, collect())
                ->map(fn (array $i) => ['start' => $i['start'], 'end' => $i['end'], 'type' => $i['type']])
                ->values()
                ->all();
        }

        return $bySpot;
    }

    /**
     * Free windows for a date: opening hours minus everything occupied.
     *
     * @return list<array{start: Carbon, end: Carbon}>
     */
    public function freeWindows(Spot $spot, Carbon $date, ?int $ignoreReservationId = null): array
    {
        $windows = $this->openWindows($spot, $date);

        if ($windows === []) {
            return [];
        }

        $rangeStart = $windows[0]['start'];
        $rangeEnd = end($windows)['end'];

        $busy = $this->busyIntervals($spot, $rangeStart, $rangeEnd, $ignoreReservationId);

        return $this->carve($windows, $busy);
    }

    /**
     * Free windows for every given spot on a date, in two queries total.
     *
     * The venue page lists every spot at a venue, so this is the shape that page
     * actually needs. Callers must have the spots' venue loaded -- hours fall
     * back to it, and that fallback is the whole reason the page is here.
     *
     * @param  Collection<int, Spot>  $spots
     * @return array<int, list<array{start: Carbon, end: Carbon}>>
     */
    public function freeWindowsForMany(Collection $spots, Carbon $date): array
    {
        $windowsBySpot = [];
        $rangeStart = null;
        $rangeEnd = null;

        // Opening hours come off the already-loaded models, so this loop is free.
        foreach ($spots as $spot) {
            $windows = $this->openWindows($spot, $date);
            $windowsBySpot[$spot->id] = $windows;

            if ($windows === []) {
                continue;
            }

            $start = $windows[0]['start'];
            $end = end($windows)['end'];

            $rangeStart = $rangeStart === null || $start->lessThan($rangeStart) ? $start : $rangeStart;
            $rangeEnd = $rangeEnd === null || $end->greaterThan($rangeEnd) ? $end : $rangeEnd;
        }

        // One range covering every spot's day. Fetching a little more than each
        // spot strictly needs is far cheaper than a query per spot; carve()
        // ignores anything outside the window it is given.
        $busyBySpot = $rangeStart === null
            ? []
            : $this->busyIntervalsForMany(
                array_keys($windowsBySpot),
                $rangeStart,
                $rangeEnd,
            );

        $free = [];

        foreach ($windowsBySpot as $spotId => $windows) {
            $free[$spotId] = $windows === []
                ? []
                : $this->carve($windows, $busyBySpot[$spotId] ?? []);
        }

        return $free;
    }

    /**
     * Subtract occupied intervals from opening windows.
     *
     * @param  list<array{start: Carbon, end: Carbon}>  $windows
     * @param  list<array{start: Carbon, end: Carbon, type: string}>  $busy
     * @return list<array{start: Carbon, end: Carbon}>
     */
    private function carve(array $windows, array $busy): array
    {
        $free = [];

        foreach ($windows as $window) {
            $cursor = $window['start']->copy();

            foreach ($busy as $interval) {
                if ($interval['end']->lessThanOrEqualTo($cursor)) {
                    continue;
                }

                if ($interval['start']->greaterThanOrEqualTo($window['end'])) {
                    break;
                }

                if ($interval['start']->greaterThan($cursor)) {
                    $free[] = ['start' => $cursor->copy(), 'end' => $interval['start']->copy()];
                }

                if ($interval['end']->greaterThan($cursor)) {
                    $cursor = $interval['end']->copy();
                }
            }

            if ($cursor->lessThan($window['end'])) {
                $free[] = ['start' => $cursor->copy(), 'end' => $window['end']->copy()];
            }
        }

        return $free;
    }

    /**
     * Start times a customer could actually pick for a given duration.
     *
     * Candidates advance by the billing unit -- offering minute-by-minute start
     * times on a table billed in 10-minute blocks would produce a useless list
     * and fragment the day into unsellable gaps.
     *
     * @return list<Carbon>
     */
    public function startTimesFor(Spot $spot, Carbon $date, int $durationMinutes, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $step = $spot->price_unit_minutes;
        $earliest = $now->copy()->addMinutes((int) config('booking.min_booking_lead_minutes'));

        $starts = [];

        foreach ($this->freeWindows($spot, $date) as $window) {
            $cursor = $this->alignToStep($window['start'], $step);

            while ($cursor->copy()->addMinutes($durationMinutes)->lessThanOrEqualTo($window['end'])) {
                // Only offer times on the requested date, and only ones far
                // enough out that the owner has a chance to respond.
                if ($cursor->isSameDay($date) && $cursor->greaterThanOrEqualTo($earliest)) {
                    $starts[] = $cursor->copy();
                }

                $cursor->addMinutes($step);
            }
        }

        return $starts;
    }

    /** Whether a range sits entirely inside one opening window (SRS 9.5). */
    public function isWithinOperatingHours(Spot $spot, Carbon $start, Carbon $end): bool
    {
        foreach ($this->openWindows($spot, $start) as $window) {
            if ($start->greaterThanOrEqualTo($window['start']) && $end->lessThanOrEqualTo($window['end'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a range collides with a confirmed booking or an owner block.
     *
     * This is the read-only check used for UI feedback. The authoritative check
     * happens inside the approval transaction, under a row lock -- anything
     * evaluated outside that lock is advisory by definition.
     */
    public function isFree(Spot $spot, Carbon $start, Carbon $end, ?int $ignoreReservationId = null): bool
    {
        return $this->busyIntervals($spot, $start, $end, $ignoreReservationId) === [];
    }

    /** Whether an owner block (rather than a booking) covers the range. */
    public function isBlockedByOwner(Spot $spot, Carbon $start, Carbon $end): bool
    {
        return SpotBlock::query()
            ->forSpot($spot->id)
            ->overlapping($start, $end)
            ->exists();
    }

    /**
     * Occupancy for an owner's day view: each spot with its bookings and blocks.
     *
     * @return Collection<int, array{spot: Spot, intervals: list<array{start: Carbon, end: Carbon}>}>
     */
    public function dayOverview(Collection $spots, Carbon $date): Collection
    {
        $from = $date->copy()->startOfDay();
        $to = $date->copy()->endOfDay();

        $busy = $this->busyIntervalsForMany($spots->pluck('id')->all(), $from, $to);

        return $spots->map(fn (Spot $spot) => [
            'spot' => $spot,
            'intervals' => $busy[$spot->id] ?? [],
        ]);
    }

    /**
     * Round up to the next clean step boundary measured from midnight, so a
     * window opening at 16:07 offers 16:10 rather than 16:07 on a 10-minute table.
     */
    private function alignToStep(Carbon $time, int $step): Carbon
    {
        $minutesIntoDay = $time->hour * 60 + $time->minute;
        $remainder = $minutesIntoDay % $step;

        $aligned = $time->copy()->seconds(0);

        return $remainder === 0 ? $aligned : $aligned->addMinutes($step - $remainder);
    }
}
