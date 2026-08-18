<?php

namespace App\Support;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A weekly opening schedule.
 *
 * Stored as JSON keyed by lowercase three-letter weekday, each holding a list
 * of open/close ranges:
 *
 *   {
 *     "mon": [{"open": "16:00", "close": "23:30"}],
 *     "sat": [{"open": "10:00", "close": "13:00"},
 *             {"open": "16:00", "close": "02:00"}],
 *     "sun": []
 *   }
 *
 * A list of ranges rather than a single open/close pair, because split shifts
 * are normal here -- plenty of venues close through the afternoon and reopen
 * in the evening. An empty list (or a missing key) means closed that day.
 *
 * A close time earlier than its open time means the range runs past midnight
 * ("16:00"-"02:00" is a ten-hour evening session). This is the common case for
 * gaming venues and is handled explicitly rather than being rejected as invalid.
 */
final class OperatingHours
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const DAY_LABELS = [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
    ];

    /** @param array<string, list<array{open: string, close: string}>> $schedule */
    private function __construct(private readonly array $schedule) {}

    /**
     * Build from raw stored/submitted data, normalising as we go.
     *
     * Unknown keys are dropped and times are canonicalised to H:i, so a
     * hand-edited or legacy row can't poison availability calculations.
     */
    public static function fromArray(?array $raw): self
    {
        $schedule = [];

        foreach (self::DAYS as $day) {
            $ranges = $raw[$day] ?? [];

            if (! is_array($ranges)) {
                $ranges = [];
            }

            $normalised = [];

            foreach ($ranges as $range) {
                if (! is_array($range) || ! isset($range['open'], $range['close'])) {
                    continue;
                }

                $open = self::normaliseTime($range['open']);
                $close = self::normaliseTime($range['close']);

                if ($open === null || $close === null || $open === $close) {
                    continue;
                }

                $normalised[] = ['open' => $open, 'close' => $close];
            }

            $schedule[$day] = $normalised;
        }

        return new self($schedule);
    }

    /**
     * Build from the hours editor's submitted shape:
     *
     *   ['mon' => ['closed' => '1'],
     *    'tue' => ['ranges' => [['open' => '14:00', 'close' => '02:00']]]]
     *
     * A day marked closed discards any ranges it still carries -- the editor
     * leaves the inputs in the DOM when a day is toggled shut so the times are
     * still there if the owner toggles it back, and "closed" must win.
     */
    public static function fromFormInput(?array $input): self
    {
        $schedule = [];

        foreach (self::DAYS as $day) {
            $entry = $input[$day] ?? [];

            if (! is_array($entry) || ! empty($entry['closed'])) {
                $schedule[$day] = [];

                continue;
            }

            $schedule[$day] = array_values(
                is_array($entry['ranges'] ?? null) ? $entry['ranges'] : []
            );
        }

        return self::fromArray($schedule);
    }

    /** Every day closed -- the starting point for a new Business form. */
    public static function empty(): self
    {
        return new self(array_fill_keys(self::DAYS, []));
    }

    /** Convenience for seeding and tests: the same hours every day. */
    public static function everyDay(string $open, string $close): self
    {
        return self::fromArray(array_fill_keys(
            self::DAYS,
            [['open' => $open, 'close' => $close]]
        ));
    }

    /** @return array<string, list<array{open: string, close: string}>> */
    public function toArray(): array
    {
        return $this->schedule;
    }

    /** @return list<array{open: string, close: string}> */
    public function forDay(string $day): array
    {
        return $this->schedule[strtolower($day)] ?? [];
    }

    /** @return list<array{open: string, close: string}> */
    public function forDate(CarbonInterface $date): array
    {
        return $this->forDay(strtolower($date->format('D')));
    }

    public function isClosedOn(string $day): bool
    {
        return $this->forDay($day) === [];
    }

    /**
     * The longest single open range, in minutes -- across the whole week, or
     * just one day when $day is given. An overnight range ("22:00"-"02:00")
     * counts its full span across midnight.
     *
     * This is the "no maximum booking length, only minimum" ceiling: a
     * booking can run as long as a single open window will hold it, so the
     * UI needs to know how long that window actually is rather than an
     * owner-set number (Spot::allowedDurations()).
     */
    public function longestRangeMinutes(?string $day = null): int
    {
        $days = $day !== null ? [strtolower($day)] : self::DAYS;
        $longest = 0;

        foreach ($days as $d) {
            foreach ($this->forDay($d) as $range) {
                $longest = max($longest, self::rangeMinutes($range['open'], $range['close']));
            }
        }

        return $longest;
    }

    private static function rangeMinutes(string $open, string $close): int
    {
        [$openHour, $openMinute] = array_map('intval', explode(':', $open));
        [$closeHour, $closeMinute] = array_map('intval', explode(':', $close));

        $start = $openHour * 60 + $openMinute;
        $end = $closeHour * 60 + $closeMinute;

        if ($end <= $start) {
            $end += 24 * 60;
        }

        return $end - $start;
    }

    public function isClosedAllWeek(): bool
    {
        foreach (self::DAYS as $day) {
            if (! $this->isClosedOn($day)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a day's ranges into concrete datetime windows anchored to $date.
     *
     * A range that wraps past midnight produces a window whose end falls on the
     * following calendar day, which is what callers need for overlap checks.
     *
     * @return list<array{start: CarbonInterface, end: CarbonInterface}>
     */
    public function windowsFor(CarbonInterface $date): array
    {
        $windows = [];

        foreach ($this->forDate($date) as $range) {
            [$openHour, $openMinute] = array_map('intval', explode(':', $range['open']));
            [$closeHour, $closeMinute] = array_map('intval', explode(':', $range['close']));

            $start = $date->copy()->setTime($openHour, $openMinute);
            $end = $date->copy()->setTime($closeHour, $closeMinute);

            if ($end->lessThanOrEqualTo($start)) {
                $end = $end->addDay();
            }

            $windows[] = ['start' => $start, 'end' => $end];
        }

        return $windows;
    }

    /**
     * Human summary for listings: "Mon-Fri 4:00 PM - 11:30 PM, Sat-Sun 10:00 AM - 2:00 AM".
     *
     * Consecutive days sharing identical hours are collapsed into a range so
     * the string stays short enough for a card.
     */
    public function summary(): string
    {
        $groups = [];

        foreach (self::DAYS as $day) {
            $key = json_encode($this->forDay($day));
            $last = array_key_last($groups);

            if ($last !== null && $groups[$last]['key'] === $key) {
                $groups[$last]['days'][] = $day;

                continue;
            }

            $groups[] = ['key' => $key, 'days' => [$day], 'ranges' => $this->forDay($day)];
        }

        $parts = [];

        foreach ($groups as $group) {
            $days = $group['days'];
            $dayLabel = count($days) === 1
                ? ucfirst($days[0])
                : ucfirst($days[0]).'–'.ucfirst(end($days));

            if ($group['ranges'] === []) {
                $parts[] = $dayLabel.' closed';

                continue;
            }

            $times = array_map(
                fn (array $r) => self::displayTime($r['open']).' – '.self::displayTime($r['close']),
                $group['ranges']
            );

            $parts[] = $dayLabel.' '.implode(', ', $times);
        }

        return implode(' · ', $parts);
    }

    /** "4:00 PM" from "16:00". */
    public static function displayTime(string $time): string
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $suffix = $hour < 12 ? 'AM' : 'PM';
        $display = $hour % 12 === 0 ? 12 : $hour % 12;

        return sprintf('%d:%02d %s', $display, $minute, $suffix);
    }

    /**
     * Accept "9:00", "09:00", "09:00:00" and return canonical "09:00",
     * or null if the value isn't a usable time.
     */
    private static function normaliseTime(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        if (! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', (string) $value, $matches)) {
            return null;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    public static function assertValidDay(string $day): void
    {
        if (! in_array(strtolower($day), self::DAYS, true)) {
            throw new InvalidArgumentException("Unknown weekday [{$day}].");
        }
    }
}
