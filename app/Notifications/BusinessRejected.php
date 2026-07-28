<?php

namespace App\Notifications;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * FR-3.1 -> Owner: the venue did not pass review.
 *
 * The reason is carried through verbatim: without it the Owner has no idea what
 * to fix, and editing the listing is what puts it back in the review queue.
 */
class BusinessRejected extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Business $business,
        public readonly string $reason,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'business.rejected',
            'title' => 'Venue not approved',
            'body' => "{$this->business->name} wasn't approved: {$this->reason} "
                .'Fix the issue and save the venue to resubmit it.',
            'icon' => 'x-circle',
            'tone' => 'danger',
            'business_id' => $this->business->id,
            'business_name' => $this->business->name,
            'reason' => $this->reason,
        ];
    }
}
