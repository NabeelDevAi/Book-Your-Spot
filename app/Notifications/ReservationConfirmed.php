<?php

namespace App\Notifications;

/** FR-4.6 -> Customer: the venue accepted. */
class ReservationConfirmed extends BookingNotification
{
    public function key(): string
    {
        return 'reservation.confirmed';
    }

    public function title(): string
    {
        return 'Booking confirmed';
    }

    public function body(): string
    {
        return sprintf(
            '%s at %s is confirmed for %s. Quote %s and pay at the venue.',
            $this->reservation->spot->name,
            $this->reservation->business->name,
            $this->reservation->dateLabel().', '.$this->reservation->timeRangeLabel(),
            $this->reservation->reference,
        );
    }

    public function icon(): string
    {
        return 'check-circle';
    }

    public function tone(): string
    {
        return 'success';
    }

    protected function extra(): array
    {
        return ['total_price' => $this->reservation->totalPriceLabel()];
    }
}
