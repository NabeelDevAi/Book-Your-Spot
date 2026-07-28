<?php

namespace App\Notifications;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * FR-3.2 / SRS 9.8 -> Owner: the venue has been suspended.
 *
 * The count of cancelled bookings is included because that is the part the
 * Owner most needs to act on -- those customers have just been told their
 * booking is off, and some of them will ring the venue.
 */
class BusinessSuspended extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Business $business,
        public readonly string $reason,
        public readonly int $cancelledBookings = 0,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $body = "{$this->business->name} has been suspended: {$this->reason}";

        if ($this->cancelledBookings > 0) {
            $body .= sprintf(
                ' %d upcoming %s been cancelled and those customers notified.',
                $this->cancelledBookings,
                $this->cancelledBookings === 1 ? 'booking has' : 'bookings have',
            );
        }

        return [
            'type' => 'business.suspended',
            'title' => 'Venue suspended',
            'body' => $body,
            'icon' => 'ban',
            'tone' => 'danger',
            'business_id' => $this->business->id,
            'business_name' => $this->business->name,
            'reason' => $this->reason,
            'cancelled_bookings' => $this->cancelledBookings,
        ];
    }
}
