<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Owner-account approval gate -> Owner: the account did not pass review.
 *
 * A rejected account cannot log in (LoginRequest), so in practice the reason
 * is read from the login-form error rather than from this notification -- V1
 * has no email/SMS channel to reach someone who cannot reach the console. It
 * still exists, mirroring BusinessRejected, so the reason is on record if the
 * account is ever reinstated and the Owner can see their own history.
 */
class OwnerAccountRejected extends Notification
{
    use Queueable;

    public function __construct(public readonly string $reason) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'owner_account.rejected',
            'title' => 'Account not approved',
            'body' => "Your Owner account wasn't approved: {$this->reason}",
            'icon' => 'x-circle',
            'tone' => 'danger',
            'reason' => $this->reason,
        ];
    }
}
