<?php

namespace App\Notifications;

/** FR-4.7 -> both parties: the owner never responded in time. */
class ReservationExpired extends BookingNotification
{
    public function key(): string
    {
        return 'reservation.expired';
    }

    public function title(): string
    {
        return 'Booking request expired';
    }

    public function body(): string
    {
        return sprintf(
            'No response for %s at %s on %s, so the slot has been released.',
            $this->reservation->spot->name,
            $this->reservation->business->name,
            $this->reservation->dateLabel().', '.$this->reservation->timeRangeLabel(),
        );
    }

    public function icon(): string
    {
        return 'clock';
    }

    public function tone(): string
    {
        return 'neutral';
    }
}
