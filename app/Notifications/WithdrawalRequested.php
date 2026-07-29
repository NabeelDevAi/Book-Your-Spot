<?php

namespace App\Notifications;

use App\Models\Withdrawal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Confirms to the Owner that the money has left their balance and is queued.
 *
 * Worth sending even though the Owner just clicked the button: their balance
 * drops immediately, and a drop with no explanation is alarming.
 */
class WithdrawalRequested extends Notification
{
    use Queueable;

    public function __construct(public readonly Withdrawal $withdrawal) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'withdrawal.requested',
            'title' => 'Withdrawal requested',
            'body' => sprintf(
                '%s is on its way to %s. We\'ll confirm here once the transfer has been sent. Reference %s.',
                $this->withdrawal->amountLabel(),
                $this->withdrawal->bankName(),
                $this->withdrawal->reference,
            ),
            'icon' => 'wallet',
            'tone' => 'info',
            'withdrawal_id' => $this->withdrawal->id,
            'reference' => $this->withdrawal->reference,
        ];
    }
}
