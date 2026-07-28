<?php

namespace App\Notifications;

use App\Enums\RejectionReason;

/** FR-4.6 -> Customer: declined, with a reason (SRS 9.19). */
class ReservationRejected extends BookingNotification
{
    public function key(): string
    {
        return 'reservation.rejected';
    }

    public function title(): string
    {
        return $this->wasAutoRejected()
            ? 'Slot taken by another booking'
            : 'Booking request declined';
    }

    public function body(): string
    {
        $reason = $this->reservation->rejection_reason_code;

        $explanation = $reason instanceof RejectionReason
            ? $reason->userMessage()
            : 'The venue declined this request.';

        $note = $this->reservation->rejection_reason_text;

        return sprintf(
            '%s at %s on %s. %s%s',
            $this->reservation->spot->name,
            $this->reservation->business->name,
            $this->reservation->dateLabel(),
            $explanation,
            $note ? ' — '.$note : '',
        );
    }

    public function icon(): string
    {
        return 'x-circle';
    }

    public function tone(): string
    {
        return 'danger';
    }

    /**
     * Losing the race is not the same as being turned down. Telling the
     * customer which happened is the difference between "the venue didn't want
     * me" and "someone else got there first".
     */
    private function wasAutoRejected(): bool
    {
        return $this->reservation->rejection_reason_code === RejectionReason::SlotTaken;
    }

    protected function extra(): array
    {
        return [
            'reason_code' => $this->reservation->rejection_reason_code?->value,
            'auto_rejected' => $this->wasAutoRejected(),
        ];
    }
}
