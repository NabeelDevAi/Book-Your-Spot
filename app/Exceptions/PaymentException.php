<?php

namespace App\Exceptions;

use App\Support\Money;
use RuntimeException;

/**
 * A payment could not be started, verified or read.
 *
 * Distinct from WalletException: nothing here has touched the ledger. These are
 * failures at the gateway boundary, before or instead of money moving.
 */
class PaymentException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'amount')
    {
        parent::__construct($message);
    }

    public static function belowMinimum(int $minimumMinor): self
    {
        return new self('The smallest top-up is '.Money::pkrMinor($minimumMinor).'.');
    }

    public static function aboveMaximum(int $maximumMinor): self
    {
        return new self('The largest single top-up is '.Money::pkrMinor($maximumMinor).'.');
    }

    /**
     * Written for the customer: they do not need to know whether it was a
     * timeout, a bad key or an outage, only that their money was not taken.
     */
    public static function gatewayUnavailable(): self
    {
        return new self('We could not reach the payment provider. No money has been taken — please try again.');
    }

    public static function notConfigured(): self
    {
        return new self('Top-ups are not available at the moment.');
    }

    /**
     * The signature did not verify. Without this check the webhook endpoint is
     * an unauthenticated "credit my wallet" API, so this is treated as hostile
     * rather than as a fault.
     */
    public static function invalidSignature(): self
    {
        return new self('Webhook signature verification failed.', field: 'signature');
    }

    public static function unknownIntent(string $intentId): self
    {
        return new self("No top-up matches payment intent {$intentId}.", field: 'intent');
    }

    public static function refundFailed(): self
    {
        return new self(
            'The payment provider refused the refund. Nothing has been deducted from the wallet.',
            field: 'refund',
        );
    }

    public static function notRefundable(): self
    {
        return new self('Only a successfully settled top-up can be refunded.', field: 'refund');
    }
}
