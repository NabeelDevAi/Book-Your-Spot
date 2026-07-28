<?php

namespace App\Notifications;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** FR-3.2 -> Owner: the suspension has been lifted. */
class BusinessReinstated extends Notification
{
    use Queueable;

    public function __construct(public readonly Business $business) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'business.reinstated',
            'title' => 'Venue reinstated',
            'body' => "{$this->business->name} is live again and taking bookings. "
                .'Bookings cancelled during the suspension were not restored.',
            'icon' => 'refresh',
            'tone' => 'success',
            'business_id' => $this->business->id,
            'business_name' => $this->business->name,
        ];
    }
}
