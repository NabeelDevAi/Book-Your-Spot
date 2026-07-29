<?php

namespace App\Enums;

/**
 * Lifecycle of an Owner cashing out.
 *
 * Settlement is a manual bank transfer performed by an Admin, so these states
 * track a human process rather than an API. That is not a temporary shortcut:
 * payouts to Pakistani bank accounts have no card-processor path, so the
 * outbound leg was always going to run over local rails.
 *
 * The money leaves the Owner's balance at `requested`, not at `paid`. Debiting
 * later would let an Owner queue three withdrawals against one balance and
 * leave the platform to discover it at settlement time.
 */
enum WithdrawalStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Processing = 'processing';
    case Paid = 'paid';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::Approved => 'Approved',
            self::Processing => 'Being paid',
            self::Paid => 'Paid',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
        };
    }

    /** No further transition is possible. */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Rejected, self::Failed], true);
    }

    /** The money is still out of the Owner's balance, awaiting an outcome. */
    public function isOpen(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Whether the debit needs reversing. A rejected or failed withdrawal must
     * put the money back; a paid one must not.
     */
    public function returnsFunds(): bool
    {
        return in_array($this, [self::Rejected, self::Failed], true);
    }

    /** States still sitting in the Admin queue. */
    public static function openValues(): array
    {
        return [self::Requested->value, self::Approved->value, self::Processing->value];
    }

    public function badge(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Requested => 'warning',
            self::Approved, self::Processing => 'info',
            self::Rejected, self::Failed => 'danger',
        };
    }
}
