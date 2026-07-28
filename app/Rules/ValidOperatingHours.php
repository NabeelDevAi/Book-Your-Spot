<?php

namespace App\Rules;

use App\Support\OperatingHours;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the operating-hours editor's submitted structure.
 *
 * Note what is deliberately NOT an error: a close time earlier than its open
 * time. That means the range runs past midnight ("16:00"-"02:00"), which is the
 * normal shape for a gaming venue. Rejecting it would make the platform
 * unusable for most of its target customers.
 *
 * @param  bool  $requireOpenDay  Business hours must have at least one open day;
 *                                a Spot override may legitimately close a spot
 *                                for the whole week.
 */
class ValidOperatingHours implements ValidationRule
{
    public function __construct(private readonly bool $requireOpenDay = true) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            $fail('Set the opening hours for each day.');

            return;
        }

        $hasOpenDay = false;

        foreach (OperatingHours::DAYS as $day) {
            $entry = $value[$day] ?? [];

            if (! is_array($entry) || ! empty($entry['closed'])) {
                continue;
            }

            $ranges = is_array($entry['ranges'] ?? null) ? $entry['ranges'] : [];
            $label = OperatingHours::DAY_LABELS[$day];

            if ($ranges === []) {
                $fail("Add opening hours for {$label}, or mark it closed.");

                continue;
            }

            foreach ($ranges as $range) {
                $open = is_array($range) ? ($range['open'] ?? null) : null;
                $close = is_array($range) ? ($range['close'] ?? null) : null;

                if (! $this->isTime($open) || ! $this->isTime($close)) {
                    $fail("Enter valid opening and closing times for {$label}.");

                    continue 2;
                }

                if ($open === $close) {
                    $fail("{$label}'s opening and closing times can't be the same.");

                    continue 2;
                }
            }

            if ($this->hasOverlap($ranges)) {
                $fail("{$label} has overlapping time ranges. Merge them into one.");

                continue;
            }

            $hasOpenDay = true;
        }

        if ($this->requireOpenDay && ! $hasOpenDay) {
            $fail('Your venue must be open on at least one day.');
        }
    }

    private function isTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value) === 1
            && (int) explode(':', $value)[0] <= 23
            && (int) explode(':', $value)[1] <= 59;
    }

    /**
     * Two split shifts on the same day must not collide.
     *
     * Ranges are compared in absolute minutes from midnight, with a wrapping
     * range extended past 1440 so "10:00-13:00" and "22:00-02:00" are correctly
     * seen as separate, while "10:00-14:00" and "13:00-16:00" are not.
     */
    private function hasOverlap(array $ranges): bool
    {
        $intervals = [];

        foreach ($ranges as $range) {
            $start = $this->minutes($range['open']);
            $end = $this->minutes($range['close']);

            if ($end <= $start) {
                $end += 1440;
            }

            $intervals[] = [$start, $end];
        }

        foreach ($intervals as $i => $a) {
            foreach ($intervals as $j => $b) {
                if ($i >= $j) {
                    continue;
                }

                if ($a[0] < $b[1] && $b[0] < $a[1]) {
                    return true;
                }
            }
        }

        return false;
    }

    private function minutes(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $hour * 60 + $minute;
    }
}
