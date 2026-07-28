<?php

namespace App\Notifications;

/** FR-5.1 -> Customer (and Owner): your booking starts soon. */
class ReservationReminder extends BookingNotification
{
    public function key(): string
    {
        return 'reservation.reminder';
    }

    public function title(): string
    {
        return 'Booking coming up';
    }

    public function body(): string
    {
        return sprintf(
            '%s at %s starts %s. Quote %s at the venue.',
            $this->reservation->spot->name,
            $this->reservation->business->name,
            $this->reservation->start_datetime->diffForHumans(),
            $this->reservation->reference,
        );
    }

    public function icon(): string
    {
        return 'clock';
    }

    public function tone(): string
    {
        return 'info';
    }
}
