<?php

namespace App\Notifications;

use App\Enums\UserRole;

/** SRS 9.3 / 9.4 / 9.8 -> the other party: someone withdrew. */
class ReservationCancelled extends BookingNotification
{
    public function key(): string
    {
        return 'reservation.cancelled';
    }

    public function title(): string
    {
        return match ($this->reservation->cancelled_by_role) {
            UserRole::Owner => 'Venue cancelled your booking',
            UserRole::Admin => 'Booking cancelled — venue unavailable',
            default => 'Booking cancelled',
        };
    }

    public function body(): string
    {
        $reason = $this->reservation->cancellation_reason;

        // Three distinct actors, and getting this wrong is not cosmetic. An
        // earlier version only distinguished owner from customer, so an
        // admin-initiated suspension cascade (SRS 9.8) fell through to the
        // customer branch and told people "The customer cancelled" about their
        // own booking.
        $who = match ($this->reservation->cancelled_by_role) {
            UserRole::Owner => $this->reservation->business->name,
            UserRole::Admin => 'We',
            default => 'The customer',
        };

        return sprintf(
            '%s cancelled %s at %s on %s.%s%s',
            $who,
            $this->reservation->spot->name,
            $this->reservation->business->name,
            $this->reservation->dateLabel().', '.$this->reservation->timeRangeLabel(),
            $reason ? ' '.$reason : '',
            // SRS 9.3: no financial penalty is possible in V1, so visibility is
            // the entire remedy for a late cancellation.
            $this->reservation->is_late_cancellation ? ' (cancelled close to the start time)' : '',
        );
    }

    public function icon(): string
    {
        return 'x-circle';
    }

    public function tone(): string
    {
        return $this->reservation->is_late_cancellation ? 'warning' : 'neutral';
    }

    protected function extra(): array
    {
        return [
            'late' => $this->reservation->is_late_cancellation,
            'by_role' => $this->reservation->cancelled_by_role?->value,
        ];
    }
}
