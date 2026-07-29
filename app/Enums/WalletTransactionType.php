<?php

namespace App\Enums;

/**
 * Every reason money moves. One case per distinct economic event, because the
 * ledger's value in a dispute is being able to say what happened, not merely
 * that a number changed.
 *
 * The bucket each type writes to is fixed (see `bucket()`), so a caller cannot
 * accidentally credit an Owner's withdrawable balance where it meant to credit
 * pending earnings.
 */
enum WalletTransactionType: string
{
    // Customer side
    case Topup = 'topup';
    case BookingPayment = 'booking_payment';
    case BookingRefund = 'booking_refund';

    // Owner side
    case BookingEarning = 'booking_earning';
    case EarningReversal = 'earning_reversal';
    case EarningMaturedOut = 'earning_matured_out';
    case EarningMaturedIn = 'earning_matured_in';

    // Withdrawal (Phase 6)
    case WithdrawalDebit = 'withdrawal_debit';
    case WithdrawalReversal = 'withdrawal_reversal';

    // Exceptional
    case AdminAdjustment = 'admin_adjustment';
    case ChargebackReversal = 'chargeback_reversal';

    public function label(): string
    {
        return match ($this) {
            self::Topup => 'Top-up',
            self::BookingPayment => 'Booking payment',
            self::BookingRefund => 'Booking refund',
            self::BookingEarning => 'Booking earning',
            self::EarningReversal => 'Earning reversed',
            self::EarningMaturedOut, self::EarningMaturedIn => 'Earnings released',
            self::WithdrawalDebit => 'Withdrawal',
            self::WithdrawalReversal => 'Withdrawal returned',
            self::AdminAdjustment => 'Adjustment',
            self::ChargebackReversal => 'Chargeback',
        };
    }

    /**
     * Which pot this type writes to.
     *
     * `AdminAdjustment` is the one type that can target either, so it is not
     * listed here -- callers must pass the bucket explicitly.
     */
    public function bucket(): LedgerBucket
    {
        return match ($this) {
            self::BookingEarning,
            self::EarningReversal,
            self::EarningMaturedOut => LedgerBucket::Pending,

            default => LedgerBucket::Balance,
        };
    }

    /** Sign the amount takes in the ledger. */
    public function isCredit(): bool
    {
        return match ($this) {
            self::Topup,
            self::BookingRefund,
            self::BookingEarning,
            self::EarningMaturedIn,
            self::WithdrawalReversal => true,

            default => false,
        };
    }

    /**
     * Types a customer should see on their statement as money leaving to a
     * venue, as opposed to internal bookkeeping. Used by the wallet UI.
     */
    public function isVisibleToCustomer(): bool
    {
        return match ($this) {
            self::EarningMaturedOut, self::EarningMaturedIn => false,
            default => true,
        };
    }
}
