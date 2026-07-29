<?php

namespace App\Exceptions;

use App\Support\Money;
use RuntimeException;

/**
 * A withdrawal could not be requested or settled.
 */
class WithdrawalException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'amount')
    {
        parent::__construct($message);
    }

    public static function belowMinimum(int $minimumMinor): self
    {
        return new self('The smallest withdrawal is '.Money::pkrMinor($minimumMinor).'.');
    }

    /**
     * Withdrawable means MATURED. Earnings from a booking still inside its
     * dispute window are deliberately excluded, and the message says so --
     * "insufficient balance" would be baffling to an Owner looking at a
     * healthy-looking earnings total.
     */
    public static function insufficientBalance(int $requestedMinor, int $availableMinor, int $pendingMinor): self
    {
        $message = sprintf(
            'You have %s available to withdraw but asked for %s.',
            Money::pkrMinor($availableMinor),
            Money::pkrMinor($requestedMinor),
        );

        if ($pendingMinor > 0) {
            $message .= sprintf(
                ' A further %s is still clearing and becomes available once those bookings settle.',
                Money::pkrMinor($pendingMinor),
            );
        }

        return new self($message);
    }

    public static function noPayoutAccount(): self
    {
        return new self(
            'Add the bank account you want to be paid into before requesting a withdrawal.',
            field: 'payout_account_id',
        );
    }

    public static function accountNotYours(): self
    {
        return new self('That payout account does not belong to you.', field: 'payout_account_id');
    }

    public static function alreadyResolved(string $status): self
    {
        return new self("This withdrawal was already marked {$status} and cannot be changed.", field: 'status');
    }

    public static function walletFrozen(): self
    {
        return new self('This wallet is frozen while a payment issue is reviewed, so withdrawals are paused.');
    }
}
