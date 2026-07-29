<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Geometry for the availability ribbon.
 *
 * The ribbon is the product's signature component: a day rendered as a single
 * 24-hour track, with free time lit and taken time in shadow. Competitors are
 * photo grids; this platform's actual substance is *when can I get in*, so
 * that is what gets drawn.
 *
 * This class does one job -- turn wall-clock intervals into percentages along
 * that track -- and it lives outside the Blade component so the awkward parts
 * are testable. The awkward parts are all about midnight:
 *
 *   Venues here routinely run past it. Cue & Console closes at 2 AM, which
 *   means a booking that starts at 11 PM belongs to the day it started on and
 *   has to be clipped at the right-hand edge rather than wrapping around or
 *   drawing off the end of its own track.
 *
 *   A block that started yesterday and runs into this morning has the mirror
 *   problem at the left edge.
 *
 * Percentages, not pixels: the same numbers drive a 6px micro ribbon on a card
 * and a full-width interactive one on the booking page.
 */
final class Ribbon
{
    private const MINUTES_PER_DAY = 1440;

    /** Below this a segment is invisible, so it is widened to stay legible. */
    private const MIN_WIDTH_PERCENT = 0.5;

    /**
     * Clip intervals to a single day and express them as track percentages.
     *
     * @param  array<int, array{start: CarbonInterface, end: CarbonInterface, type?: string, label?: string}>  $intervals
     * @return array<int, array{left: float, width: float, type: string, label: string}>
     */
    public static function segments(CarbonInterface $day, array $intervals): array
    {
        $dayStart = $day->copy()->startOfDay();
        $dayEnd = $dayStart->copy()->addDay();
        $segments = [];

        foreach ($intervals as $interval) {
            $start = $interval['start'];
            $end = $interval['end'];

            // Drop anything that does not touch this day at all, rather than
            // letting it clamp to a zero-width sliver at one edge.
            if ($end->lessThanOrEqualTo($dayStart) || $start->greaterThanOrEqualTo($dayEnd)) {
                continue;
            }

            $clippedStart = $start->lessThan($dayStart) ? $dayStart : $start;
            $clippedEnd = $end->greaterThan($dayEnd) ? $dayEnd : $end;

            $left = self::percentOfDay($dayStart, $clippedStart);
            $width = max(
                self::MIN_WIDTH_PERCENT,
                self::percentOfDay($dayStart, $clippedEnd) - $left
            );

            $segments[] = [
                'left' => round($left, 3),
                // Never let a rounded-up sliver overflow the right edge.
                'width' => round(min($width, 100 - $left), 3),
                'type' => $interval['type'] ?? 'booking',
                'label' => $interval['label'] ?? $clippedStart->format('g:i A').' – '.$clippedEnd->format('g:i A'),
            ];
        }

        return $segments;
    }

    /**
     * Flatten several spots' free windows into one venue-level view.
     *
     * A venue card shows the venue, not a spot, so the only honest reading is
     * "something here is bookable at this time" -- if any one spot is free the
     * venue is free. That means a union, and a union needs the overlaps merged:
     * six spots each free 2-6 PM must render as one lit band, not six stacked
     * translucent ones that pile up into a darker, meaningless smear.
     *
     * Touching windows are merged too (one ending exactly where the next
     * begins), so a spot free 2-4 PM and another free 4-6 PM reads as a
     * continuous 2-6 PM rather than showing a seam at a time nothing happens.
     *
     * @param  array<int, list<array{start: CarbonInterface, end: CarbonInterface}>>  $windowSets
     * @return list<array{start: CarbonInterface, end: CarbonInterface}>
     */
    public static function merge(array $windowSets): array
    {
        $windows = [];

        foreach ($windowSets as $set) {
            foreach ($set as $window) {
                $windows[] = $window;
            }
        }

        if ($windows === []) {
            return [];
        }

        usort($windows, fn ($a, $b) => $a['start']->getTimestamp() <=> $b['start']->getTimestamp());

        $merged = [array_shift($windows)];

        foreach ($windows as $window) {
            $last = &$merged[count($merged) - 1];

            if ($window['start']->greaterThan($last['end'])) {
                $merged[] = $window;

                continue;
            }

            if ($window['end']->greaterThan($last['end'])) {
                $last['end'] = $window['end'];
            }
        }

        return $merged;
    }

    /**
     * Where "now" sits on the track, or null when the day is not today.
     *
     * Returning null rather than 0 or 100 matters: the marker claims to show
     * the current moment, so on any other day it must not be drawn at all.
     */
    public static function nowOffset(CarbonInterface $day, ?CarbonInterface $now = null): ?float
    {
        $now ??= $day->copy()->nowWithSameTz();

        if (! $now->isSameDay($day)) {
            return null;
        }

        return round(self::percentOfDay($day->copy()->startOfDay(), $now), 3);
    }

    /**
     * Hour marks for the track's scale, every `$every` hours.
     *
     * @return array<int, array{left: float, label: string}>
     */
    public static function hourMarks(int $every = 6): array
    {
        $marks = [];

        for ($hour = 0; $hour <= 24; $hour += $every) {
            $marks[] = [
                'left' => round($hour / 24 * 100, 3),
                'label' => self::hourLabel($hour),
            ];
        }

        return $marks;
    }

    private static function percentOfDay(CarbonInterface $dayStart, CarbonInterface $moment): float
    {
        $minutes = $dayStart->diffInMinutes($moment, absolute: true);

        return min(100, max(0, $minutes / self::MINUTES_PER_DAY * 100));
    }

    private static function hourLabel(int $hour): string
    {
        return match (true) {
            $hour === 0, $hour === 24 => '12 AM',
            $hour === 12 => '12 PM',
            $hour < 12 => $hour.' AM',
            default => ($hour - 12).' PM',
        };
    }
}
