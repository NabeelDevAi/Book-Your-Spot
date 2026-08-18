<?php

namespace App\Services\Booking;

use App\Models\Holiday;
use Illuminate\Support\Carbon;

/**
 * Decides whether a date bills at a Spot's weekend rate.
 *
 * Three independent reasons a date counts as "weekend": it falls on a
 * platform-wide weekend day (Sat/Sun, config('booking.weekend_days')), it is
 * itself a listed Holiday, or it is the calendar day immediately BEFORE a
 * listed Holiday -- the agreed rule is that the day a venue fills up ahead of
 * a holiday prices the same as the holiday itself.
 *
 * Holiday dates are loaded once per request and cached in memory: this is
 * consulted once per booking, but also once per candidate date when a search
 * or availability sweep checks a whole week, and there are at most a
 * handful of holidays on the calendar at any time.
 */
class HolidayCalendar
{
    /** @var array<string, true>|null */
    private ?array $holidayDates = null;

    public function isWeekendRate(Carbon $date): bool
    {
        if (in_array($date->dayOfWeek, config('booking.weekend_days'), true)) {
            return true;
        }

        $dates = $this->holidayDates();
        $key = $date->format('Y-m-d');
        $dayBefore = $date->copy()->addDay()->format('Y-m-d');

        return isset($dates[$key]) || isset($dates[$dayBefore]);
    }

    /** @return array<string, true> */
    private function holidayDates(): array
    {
        if ($this->holidayDates !== null) {
            return $this->holidayDates;
        }

        return $this->holidayDates = Holiday::query()
            ->pluck('date')
            ->mapWithKeys(fn ($date) => [$date->format('Y-m-d') => true])
            ->all();
    }
}
