<?php

namespace App\Enums;

/**
 * Lifecycle of a wallet top-up.
 *
 * `pending` means the attempt was recorded but the gateway has not confirmed
 * it. Against the current simulated gateway that state barely exists -- it
 * settles synchronously, so a top-up is `succeeded` before `start()` returns.
 * It is kept because an asynchronous provider needs it, and because the gap
 * between "the payment was taken" and "the wallet was credited" is exactly
 * where money goes missing.
 *
 * `succeeded` is set ONLY by TopupService::fulfil(), never by a browser request
 * claiming a payment worked.
 */
enum TopupStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting payment',
            self::Succeeded => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isTerminal(): bool
    {
        return $this !== self::Pending;
    }

    /** Only a succeeded top-up has moved money into a wallet. */
    public function hasCredited(): bool
    {
        return $this === self::Succeeded;
    }

    public function badge(): string
    {
        return match ($this) {
            self::Succeeded => 'success',
            self::Pending => 'warning',
            self::Failed => 'danger',
            self::Cancelled => 'neutral',
        };
    }
}
