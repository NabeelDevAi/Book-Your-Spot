<?php

namespace App\Enums;

/**
 * Whether a wallet may move money.
 *
 * `frozen` exists for chargeback and fraud handling: a disputed top-up must
 * stop the balance being spent immediately, before anyone investigates. It
 * blocks every operation except an Admin adjustment, because the Admin needs a
 * way to unwind the position while the freeze is still in force.
 */
enum WalletStatus: string
{
    case Active = 'active';
    case Frozen = 'frozen';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Frozen => 'Frozen',
        };
    }

    public function canTransact(): bool
    {
        return $this === self::Active;
    }

    public function badge(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Frozen => 'danger',
        };
    }
}
