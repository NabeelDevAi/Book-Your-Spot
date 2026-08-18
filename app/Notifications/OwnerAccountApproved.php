<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Owner-account approval gate -> Owner: the account passed review and can now log in. */
class OwnerAccountApproved extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'owner_account.approved',
            'title' => 'Account approved',
            'body' => 'Your Owner account has been approved. Add your venue to get listed.',
            'icon' => 'check-circle',
            'tone' => 'success',
        ];
    }
}
