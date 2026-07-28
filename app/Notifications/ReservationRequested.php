<?php

namespace App\Notifications;

/** FR-4.5 -> Owner: a customer has asked for a slot. */
class ReservationRequested extends BookingNotification
{
    public function key(): string
    {
        return 'reservation.requested';
    }

    public function title(): string
    {
        return 'New booking request';
    }

    public function body(): string
    {
        return sprintf(
            '%s wants %s on %s. Respond by %s.',
            $this->reservation->user->name,
            $this->reservation->spot->name,
            $this->reservation->dateLabel().' at '.$this->reservation->start_datetime->format('g:i A'),
            $this->reservation->response_deadline->format('D j M, g:i A'),
        );
    }

    public function icon(): string
    {
        return 'bell';
    }

    public function tone(): string
    {
        return 'warning';
    }

    protected function extra(): array
    {
        return [
            'customer_name' => $this->reservation->user->name,
            // SRS 9.12: the owner sees the customer's no-show history at the
            // moment they decide, which is the only deterrent V1 has.
            'customer_no_show_count' => $this->reservation->user->no_show_count,
            'respond_by' => $this->reservation->response_deadline->toIso8601String(),
        ];
    }
}
