<?php

namespace App\Notifications;

use App\Models\Business;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** FR-3.1 -> Owner: the venue passed review and is now live. */
class BusinessApproved extends Notification
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
            'type' => 'business.approved',
            'title' => 'Venue approved',
            'body' => "{$this->business->name} is now live and customers can book it.",
            'icon' => 'check-circle',
            'tone' => 'success',
            'business_id' => $this->business->id,
            'business_name' => $this->business->name,
        ];
    }
}
