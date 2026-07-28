<?php

namespace App\Enums;

/**
 * The seven reservation states from SRS section 5.
 *
 *                    pending
 *      ┌───────────────┼───────────────┬──────────────┐
 *   confirmed      rejected         expired       cancelled
 *      │        (owner declined)  (no response)   (by user)
 *      ├──────────────┬───────────────┐
 *  completed      no_show         cancelled
 * (time passed)  (owner flagged)  (either party)
 *
 * Note on `rejected`: an Owner approving one request auto-rejects every other
 * overlapping pending request on that Spot. Those carry the `slot_taken`
 * rejection reason so the User is told what actually happened rather than
 * believing the Owner declined them personally.
 */
enum ReservationStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
            self::NoShow => 'No-show',
        };
    }

    /**
     * States that occupy the Spot and therefore block other bookings.
     *
     * IMPORTANT: `pending` is deliberately NOT here. Multiple Users may hold
     * pending requests on the same slot; the first approval wins and the rest
     * are auto-rejected. This amends FR-4.4/NFR-1 as written and moves the
     * double-booking guarantee from request time to approval time.
     */
    public static function blocking(): array
    {
        return [self::Confirmed];
    }

    /** @return array<string> backing values, for query `whereIn` clauses. */
    public static function blockingValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::blocking());
    }

    /** States still awaiting an outcome -- shown under "upcoming" for a User. */
    public static function open(): array
    {
        return [self::Pending, self::Confirmed];
    }

    /** @return array<string> */
    public static function openValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::open());
    }

    /** No further transition is possible from a terminal state. */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Rejected,
            self::Expired,
            self::Cancelled,
            self::Completed,
            self::NoShow,
        ], true);
    }

    /** Whether the User may still cancel (FR-4.9). */
    public function isCancellable(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed], true);
    }

    /** Whether the Owner still owes a decision. */
    public function awaitsOwner(): bool
    {
        return $this === self::Pending;
    }

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Confirmed => 'success',
            self::Completed => 'info',
            self::Rejected, self::NoShow => 'danger',
            self::Expired, self::Cancelled => 'neutral',
        };
    }
}
