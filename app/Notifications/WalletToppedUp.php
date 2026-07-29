<?php

namespace App\Notifications;

use App\Models\Topup;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * The customer's money arrived.
 *
 * In-app only, like everything else in V1 -- but this is the notification most
 * worth promoting to email or SMS first when a delivery channel exists. "Did my
 * payment go through?" is the question people actually chase, and the
 * notification bell only answers it for someone already looking at the site.
 */
class WalletToppedUp extends Notification
{
    use Queueable;

    /**
     * The resulting balance is passed in rather than read off the notifiable.
     *
     * Two reasons. Reading `$notifiable->wallet` here would lazy-load, which
     * `preventLazyLoading()` turns into a hard failure in local and testing.
     * More importantly the caller already holds the locked wallet at the exact
     * moment the credit lands, so it knows the correct figure -- a re-read
     * could pick up a later, unrelated movement.
     */
    public function __construct(
        public readonly Topup $topup,
        public readonly int $resultingBalanceMinor,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'wallet.topped_up',
            'title' => 'Wallet topped up',
            'body' => sprintf(
                '%s has been added to your wallet. Your balance is now %s.',
                Money::pkrMinor($this->topup->amount_minor),
                Money::pkrMinor($this->resultingBalanceMinor),
            ),
            'icon' => 'wallet',
            'tone' => 'success',
            'topup_id' => $this->topup->id,
            'amount' => Money::pkrMinor($this->topup->amount_minor),
        ];
    }
}
