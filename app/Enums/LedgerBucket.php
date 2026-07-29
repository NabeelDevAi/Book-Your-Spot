<?php

namespace App\Enums;

/**
 * Which balance column a ledger row moved.
 *
 * A wallet holds two independent pots and a ledger row always belongs to
 * exactly one of them:
 *
 *   balance  -- settled funds. Spendable by a customer, withdrawable by an Owner.
 *   pending  -- an Owner's earnings that have not matured yet.
 *
 * Earnings land in `pending` at approval and only move to `balance` once the
 * booking has completed and the dispute window has passed. That gap is what
 * makes a refund possible without ever clawing money back out of an account
 * the Owner has already withdrawn from.
 *
 * Because a row belongs to one bucket, maturity is recorded as TWO rows -- one
 * debiting `pending`, one crediting `balance`. A single row could not carry a
 * meaningful `balance_after_minor` for both.
 */
enum LedgerBucket: string
{
    case Balance = 'balance';
    case Pending = 'pending';

    public function column(): string
    {
        return match ($this) {
            self::Balance => 'balance_minor',
            self::Pending => 'pending_minor',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Balance => 'Balance',
            self::Pending => 'Pending',
        };
    }
}
