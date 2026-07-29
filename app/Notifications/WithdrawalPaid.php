<?php

namespace App\Notifications;

use App\Models\Withdrawal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** The transfer has been sent. */
class WithdrawalPaid extends Notification
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
            'type' => 'withdrawal.paid',
            'title' => 'Withdrawal sent',
            'body' => sprintf(
                '%s has been transferred to %s. Bank transfers usually land within one working day. Reference %s.',
                $this->withdrawal->amountLabel(),
                $this->withdrawal->bankName(),
                $this->withdrawal->reference,
            ),
            'icon' => 'check-circle',
            'tone' => 'success',
            'withdrawal_id' => $this->withdrawal->id,
            'reference' => $this->withdrawal->reference,
            'external_reference' => $this->withdrawal->external_reference,
        ];
    }
}
