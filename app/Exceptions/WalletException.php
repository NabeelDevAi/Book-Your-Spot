<?php

namespace App\Exceptions;

use App\Support\Money;
use RuntimeException;

/**
 * A money operation was refused.
 *
 * Two audiences share this class, which is deliberate. `insufficientFunds()`
 * is written for the customer and surfaces in the booking form. The invariant
 * failures below are written for whoever is reading the logs at 2am -- they
 * mean the ledger was about to become wrong, and the only correct response is
 * to abort the transaction loudly.
 *
 * Nothing here is recoverable by retrying. A clamped balance is a lost rupee
 * that nobody notices until reconciliation, so these throw rather than adjust.
 */
class WalletException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $field = 'amount',
        public readonly ?int $shortfallMinor = null,
    ) {
        parent::__construct($message);
    }

    /**
     * The customer cannot cover this booking. Message names the exact shortfall
     * so the top-up page can be pre-filled rather than making them work it out.
     */
    public static function insufficientFunds(int $requiredMinor, int $availableMinor): self
    {
        $shortfall = $requiredMinor - $availableMinor;

        return new self(
            sprintf(
                'Your wallet has %s available but this booking costs %s. Please top up %s to continue.',
                Money::pkrMinor($availableMinor),
                Money::pkrMinor($requiredMinor),
                Money::pkrMinor($shortfall),
            ),
            shortfallMinor: $shortfall,
        );
    }

    public static function walletFrozen(): self
    {
        return new self(
            'This wallet is temporarily frozen while a payment issue is reviewed. Please contact support.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Invariant failures -- these are bugs, not user errors
    |--------------------------------------------------------------------------
    */

    public static function nonPositiveAmount(int $minor): self
    {
        return new self("Wallet amounts must be positive; got {$minor} paisa.");
    }

    public static function negativeBalance(string $bucket, int $resulting): self
    {
        return new self(
            "Refused: {$bucket} would fall to {$resulting} paisa. Wallet balances cannot go negative.",
        );
    }

    public static function heldExceedsBalance(int $held, int $balance): self
    {
        return new self(
            "Refused: holds would total {$held} paisa against a balance of {$balance} paisa.",
        );
    }

    public static function holdAlreadyResolved(string $status): self
    {
        return new self("This hold was already {$status} and cannot be resolved twice.");
    }

    public static function holdMismatch(): self
    {
        return new self('The hold does not belong to the wallet it is being resolved against.');
    }

    /**
     * Guards every mutating method. Money must commit or roll back with the
     * reservation status it belongs to, so an unwrapped call is always a bug.
     */
    public static function outsideTransaction(string $operation): self
    {
        return new self("{$operation}() must be called inside a database transaction.");
    }
}
