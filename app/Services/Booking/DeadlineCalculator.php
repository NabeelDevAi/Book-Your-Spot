<?php

namespace App\Services\Booking;

use Illuminate\Support\Carbon;

/**
 * Works out when an unactioned `pending` reservation auto-expires (SRS 9.2).
 *
 * The rule agreed for V1:
 *
 *   if (now < start - 12h)   deadline = start - 12h
 *   else                     deadline = min(now + 2h, start - 1h)
 *
 * then clamped to at least (now + 15m) and never later than the slot start.
 *
 * The two branches exist because a single rule cannot serve both cases. A fixed
 * window from request time would let a booking made two weeks out sit unactioned
 * for two weeks, blocking nothing but piling up. A fixed lead before start would
 * give the Owner no window at all on a same-day request. So: far-out bookings
 * expire at a fixed lead time, near-term bookings get a short response window.
 *
 * The final clamp matters more than it looks. Without it, a request made 40
 * minutes before the slot would compute `start - 1h`, a deadline already in the
 * past, and the reservation would be born expired -- rejected by the very next
 * scheduler tick before the Owner ever saw it.
 *
 * All values live in config/booking.php.
 */
class DeadlineCalculator
{
    public function for(Carbon $slotStart, ?Carbon $requestedAt = null): Carbon
    {
        $now = $requestedAt?->copy() ?? Carbon::now();

        $leadHours = (int) config('booking.response_lead_hours');
        $windowHours = (int) config('booking.response_window_hours');
        $floorMinutes = (int) config('booking.late_response_floor_minutes');
        $bufferMinutes = (int) config('booking.min_response_buffer_minutes');

        $leadDeadline = $slotStart->copy()->subHours($leadHours);

        if ($now->lessThan($leadDeadline)) {
            $deadline = $leadDeadline;
        } else {
            $windowDeadline = $now->copy()->addHours($windowHours);
            $floorDeadline = $slotStart->copy()->subMinutes($floorMinutes);

            $deadline = $windowDeadline->lessThan($floorDeadline)
                ? $windowDeadline
                : $floorDeadline;
        }

        // Never already-expired: give the Owner at least a minimum buffer.
        $minimum = $now->copy()->addMinutes($bufferMinutes);

        if ($deadline->lessThan($minimum)) {
            $deadline = $minimum;
        }

        // Never past the slot start -- an expiry after the booking has begun
        // is meaningless.
        if ($deadline->greaterThan($slotStart)) {
            $deadline = $slotStart->copy();
        }

        return $deadline;
    }
}
