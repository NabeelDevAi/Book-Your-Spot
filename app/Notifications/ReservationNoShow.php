<?php

namespace App\Notifications;

/** FR-2.8 -> Customer: the venue recorded a no-show (SRS 9.12). */
class ReservationNoShow extends BookingNotification
{
    public function key(): string
    {
        return 'reservation.no_show';
    }

    public function title(): string
    {
        return 'Recorded as a no-show';
    }

    public function body(): string
    {
        return sprintf(
            '%s marked %s on %s as a no-show. Repeated no-shows are visible to venues '
            .'when they review your future requests.',
            $this->reservation->business->name,
            $this->reservation->spot->name,
            $this->reservation->dateLabel().', '.$this->reservation->timeRangeLabel(),
        );
    }

    public function icon(): string
    {
        return 'flag';
    }

    public function tone(): string
    {
        return 'danger';
    }
}
