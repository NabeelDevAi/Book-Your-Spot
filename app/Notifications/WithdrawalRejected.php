<?php

namespace App\Notifications;

use App\Enums\WithdrawalStatus;
use App\Models\Withdrawal;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The withdrawal was refused or the transfer bounced.
 *
 * Leads with the fact the money is back, because that is the Owner's first
 * question and the reason is only their second.
 */
class WithdrawalRejected extends Notification
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
        $failed = $this->withdrawal->status === WithdrawalStatus::Failed;

        return [
            'type' => 'withdrawal.'.($failed ? 'failed' : 'rejected'),
            'title' => $failed ? 'Withdrawal failed' : 'Withdrawal declined',
            'body' => sprintf(
                '%s has been returned to your balance. %s Reference %s.',
                $this->withdrawal->amountLabel(),
                $this->withdrawal->failure_reason ?: '',
                $this->withdrawal->reference,
            ),
            'icon' => 'alert',
            'tone' => 'danger',
            'withdrawal_id' => $this->withdrawal->id,
            'reference' => $this->withdrawal->reference,
        ];
    }
}
