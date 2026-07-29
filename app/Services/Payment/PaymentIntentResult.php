<?php

namespace App\Services\Payment;

/**
 * A gateway's view of one payment, normalised.
 *
 * Deliberately provider-agnostic. Everything downstream -- TopupService, the
 * reconcile sweep, the tests -- reads this shape, so replacing the gateway does
 * not ripple past the implementation class.
 */
final readonly class PaymentIntentResult
{
    public function __construct(
        public string $id,
        public int $amountMinor,
        public string $status,
        public ?string $clientSecret = null,
        public ?string $chargeId = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
    ) {}

    /** The money is captured and settled. Nothing else counts as paid. */
    public function isSucceeded(): bool
    {
        return $this->status === 'succeeded';
    }

    /** Terminally dead: the customer will have to start again. */
    public function isCancelled(): bool
    {
        return $this->status === 'canceled';
    }

    /**
     * Still in flight -- the customer has not finished, or the card is being
     * authenticated. Neither succeeded nor failed.
     */
    public function isPending(): bool
    {
        return ! $this->isSucceeded() && ! $this->isCancelled();
    }
}
