<?php

namespace App\Services\Booking;

use App\Exceptions\BookingException;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Every rule a booking request must satisfy, in one place.
 *
 * Kept out of a FormRequest deliberately. These rules must hold no matter how a
 * reservation is created -- web form, seeder, a future API, an admin acting on
 * a customer's behalf -- and validation attached to one HTTP endpoint protects
 * exactly that endpoint.
 *
 * Order matters: the cheapest and most fundamental checks run first, so a
 * suspended owner poking at a deleted spot gets the honest reason rather than a
 * confusing message about billing units.
 */
class BookingValidator
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly PricingCalculator $pricing,
    ) {}

    /**
     * @throws BookingException
     */
    public function validate(Spot $spot, User $user, Carbon $start, int $durationMinutes): void
    {
        $this->assertUserMayBook($user);
        $this->assertSpotIsBookable($spot);
        $this->assertDurationIsValid($spot, $durationMinutes, $start);

        $end = $start->copy()->addMinutes($durationMinutes);

        $this->assertTimingIsSane($start);
        $this->assertWithinOperatingHours($spot, $start, $end);
        $this->assertNotBlocked($spot, $start, $end);
        $this->assertSlotIsFree($spot, $start, $end);
        $this->assertNoDuplicateRequest($spot, $user, $start, $end);
        $this->assertUnderPendingCap($user);
    }

    /**
     * The subset of the rules above that still apply when the Owner records a
     * walk-in or phone booking on the customer's behalf.
     *
     * Deliberately skips assertUserMayBook (there may be no User account),
     * assertNoDuplicateRequest and assertUnderPendingCap (both exist to stop a
     * customer gaming the request queue, which does not apply to the venue's
     * own staff), and the platform's minimum-lead-time rule -- a walk-in is
     * standing at the counter, so "starting now" must be bookable.
     *
     * @throws BookingException
     */
    public function validateManual(Spot $spot, Carbon $start, int $durationMinutes): void
    {
        $this->assertSpotIsBookable($spot);
        $this->assertDurationIsValid($spot, $durationMinutes, $start);

        $end = $start->copy()->addMinutes($durationMinutes);

        $this->assertManualTimingIsSane($start, $end);
        $this->assertWithinOperatingHours($spot, $start, $end);
        $this->assertNotBlocked($spot, $start, $end);
        $this->assertSlotIsFree($spot, $start, $end);
    }

    /**
     * A manual booking is entered as it happens, so a start time a few
     * minutes ago -- the customer is already at the table -- is fine. Only a
     * booking that has already finished, or one absurdly far out, is rejected.
     */
    private function assertManualTimingIsSane(Carbon $start, Carbon $end): void
    {
        if ($end->isPast()) {
            throw BookingException::inThePast();
        }

        $maxDays = (int) config('booking.max_advance_days');

        if ($start->greaterThan(Carbon::now()->addDays($maxDays))) {
            throw BookingException::tooFarAhead($maxDays);
        }
    }

    /** SRS 9.14 -- owners never book, not even at venues they don't own. */
    private function assertUserMayBook(User $user): void
    {
        if (! $user->isActive()) {
            throw BookingException::accountSuspended();
        }

        if (! $user->role->canBook()) {
            throw BookingException::ownersCannotBook();
        }
    }

    /** SRS 9.17 -- both the spot and its venue must be live. */
    private function assertSpotIsBookable(Spot $spot): void
    {
        // This validator is the single home for the booking rules, so it must
        // hold for a seeder or an admin acting on someone's behalf, not only for
        // the controller that remembers to eager-load. One spot per request, so
        // there is no loop to turn this into an N+1.
        $spot->loadMissing('business');

        if (! $spot->isBookable()) {
            throw BookingException::notBookable();
        }
    }

    /**
     * SRS amendment -- only a minimum, an exact multiple of the billing unit.
     * There is no owner-set maximum any more; assertWithinOperatingHours()
     * below is what actually stops a booking too long to fit in the day. The
     * error carries valid alternatives for that day, because "invalid
     * duration" on its own leaves the customer guessing.
     */
    private function assertDurationIsValid(Spot $spot, int $durationMinutes, Carbon $start): void
    {
        if ($spot->isValidDuration($durationMinutes)) {
            return;
        }

        $allowed = $spot->allowedDurations($start);

        if ($durationMinutes < $spot->min_duration_minutes) {
            throw BookingException::invalidDuration(
                'The shortest booking here is '.Money::duration($spot->min_duration_minutes).'.',
                $allowed,
            );
        }

        // In range but not on a billing boundary -- the SRS's own example is
        // trying to book 7 minutes on a table billed in 10-minute blocks.
        $nearest = $this->nearestAllowed($allowed, $durationMinutes);

        throw BookingException::invalidDuration(
            sprintf(
                'This spot is booked in %s blocks. Try %s instead.',
                Money::duration($spot->price_unit_minutes),
                Money::duration($nearest),
            ),
            $allowed,
        );
    }

    /** SRS 9.15 -- never trust a client-side date picker. */
    private function assertTimingIsSane(Carbon $start): void
    {
        $now = Carbon::now();

        if ($start->lessThanOrEqualTo($now)) {
            throw BookingException::inThePast();
        }

        $leadMinutes = (int) config('booking.min_booking_lead_minutes');

        if ($start->lessThan($now->copy()->addMinutes($leadMinutes))) {
            throw BookingException::tooSoon($leadMinutes);
        }

        $maxDays = (int) config('booking.max_advance_days');

        if ($start->greaterThan($now->copy()->addDays($maxDays))) {
            throw BookingException::tooFarAhead($maxDays);
        }
    }

    /** SRS 9.5 -- spot override if present, otherwise venue hours. */
    private function assertWithinOperatingHours(Spot $spot, Carbon $start, Carbon $end): void
    {
        if (! $this->availability->isWithinOperatingHours($spot, $start, $end)) {
            throw BookingException::outsideOperatingHours($spot->effectiveHours()->summary());
        }
    }

    private function assertNotBlocked(Spot $spot, Carbon $start, Carbon $end): void
    {
        if ($this->availability->isBlockedByOwner($spot, $start, $end)) {
            throw BookingException::blockedByOwner();
        }
    }

    /**
     * Only `confirmed` bookings block. Other customers' `pending` requests are
     * deliberately ignored -- several people may request the same slot and the
     * first approval wins (the FR-4.4 amendment).
     */
    private function assertSlotIsFree(Spot $spot, Carbon $start, Carbon $end): void
    {
        if (! $this->availability->isFree($spot, $start, $end)) {
            throw BookingException::slotTaken();
        }
    }

    /**
     * The same customer twice on one slot is a double-submit or an attempt to
     * jump the queue, not two genuine requests.
     */
    private function assertNoDuplicateRequest(Spot $spot, User $user, Carbon $start, Carbon $end): void
    {
        $exists = Reservation::query()
            ->forSpot($spot->id)
            ->where('user_id', $user->id)
            ->open()
            ->overlapping($start, $end)
            ->exists();

        if ($exists) {
            throw BookingException::duplicateRequest();
        }
    }

    /** SRS 9.18 -- stops one customer tying up several owners' queues. */
    private function assertUnderPendingCap(User $user): void
    {
        $cap = (int) config('booking.max_pending_per_user');

        if ($user->reservations()->pending()->count() >= $cap) {
            throw BookingException::tooManyPending($cap);
        }
    }

    /** @param  list<int>  $allowed */
    private function nearestAllowed(array $allowed, int $target): int
    {
        if ($allowed === []) {
            return $target;
        }

        usort($allowed, fn ($a, $b) => abs($a - $target) <=> abs($b - $target));

        return $allowed[0];
    }
}
