<?php

namespace App\Contracts;

use App\Exceptions\PaymentException;
use App\Services\Payment\PaymentIntentResult;

/**
 * The payment gateway, behind an interface.
 *
 * Right now the only implementation is `SimulatedGateway`: top-ups settle
 * instantly and no card, no money and no third party is involved. That is a
 * deliberate staging decision rather than a gap. The obvious card processors
 * have no merchant support for Pakistani entities and cannot pay out to PK
 * bank accounts, so committing to one meant carrying a dependency that could
 * never go live.
 *
 * This interface is what makes introducing a real provider a new class plus a
 * container binding rather than a rewrite. Note what is NOT here: there is no
 * webhook method, because webhook plumbing is provider-specific and the two
 * providers that matter here would model it differently. A real gateway adds
 * its own controller and calls `TopupService::fulfil()`, which is already
 * idempotent for exactly that reason.
 *
 * Everything crossing this boundary is in minor units (paisa), matching the
 * ledger. No implementation may deal in rupees.
 */
interface PaymentGateway
{
    /**
     * Begin a payment.
     *
     * An implementation that settles synchronously returns an intent already in
     * `succeeded`, and TopupService credits the wallet there and then. One that
     * settles asynchronously returns a pending intent and is responsible for
     * calling `fulfil()` later.
     *
     * @param  array<string, string>  $metadata
     * @param  string  $idempotencyKey  a retry must not create a second charge
     *
     * @throws PaymentException
     */
    public function createIntent(int $amountMinor, array $metadata, string $idempotencyKey): PaymentIntentResult;

    /**
     * The gateway's current view of a payment.
     *
     * The authority when the local record and the gateway disagree, which is
     * what the reconcile sweep relies on.
     *
     * @throws PaymentException
     */
    public function retrieveIntent(string $intentId): PaymentIntentResult;

    /**
     * Send money back where it came from.
     *
     * Admin-only: a self-serve version would make the wallet withdrawable,
     * which is the line this design does not cross.
     *
     * @return string the gateway's refund reference, for the audit trail
     *
     * @throws PaymentException
     */
    public function refund(string $intentId, int $amountMinor): string;

    /** Whether this gateway settles during `createIntent()` or later. */
    public function settlesInstantly(): bool;

    /** Human name for the UI, so a simulated environment says so plainly. */
    public function name(): string;

    /** False when credentials are missing, so the UI can explain itself. */
    public function isConfigured(): bool;
}
