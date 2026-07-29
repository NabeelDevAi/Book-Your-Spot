<?php

namespace Tests\Support;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentException;
use App\Services\Payment\PaymentIntentResult;

/**
 * A controllable gateway for tests.
 *
 * `SimulatedGateway` always succeeds, which is the point of it -- but that
 * makes the failure paths untestable. This one settles instantly by default,
 * like the real binding, and can be told to fail, to settle asynchronously, or
 * to refuse a refund.
 *
 * Keeping it means the interesting cases still have coverage: a gateway that
 * dies mid-call, a top-up that never resolves, a refund the provider rejects.
 * All three will matter again the moment a real provider is plugged in.
 */
class FakePaymentGateway implements PaymentGateway
{
    /** @var array<string, PaymentIntentResult> */
    public array $intents = [];

    /** @var array<int, array{amount: int, metadata: array, idempotencyKey: string}> */
    public array $created = [];

    /** @var array<int, array{intent: string, amount: int}> */
    public array $refunds = [];

    public bool $configured = true;

    public bool $shouldFail = false;

    public bool $refundShouldFail = false;

    /** Flip to false to model a provider that confirms later. */
    public bool $instant = true;

    private int $counter = 0;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function name(): string
    {
        return 'Fake gateway';
    }

    public function settlesInstantly(): bool
    {
        return $this->instant;
    }

    public function createIntent(int $amountMinor, array $metadata, string $idempotencyKey): PaymentIntentResult
    {
        if ($this->shouldFail) {
            throw PaymentException::gatewayUnavailable();
        }

        // Honour the idempotency key the way a real gateway does: the same key
        // returns the same intent rather than a second charge.
        foreach ($this->created as $index => $call) {
            if ($call['idempotencyKey'] === $idempotencyKey) {
                return $this->intents['pi_fake_'.$index];
            }
        }

        $id = 'pi_fake_'.$this->counter;
        $this->created[$this->counter] = compact('metadata', 'idempotencyKey') + ['amount' => $amountMinor];
        $this->counter++;

        return $this->intents[$id] = new PaymentIntentResult(
            id: $id,
            amountMinor: $amountMinor,
            status: $this->instant ? 'succeeded' : 'requires_payment_method',
            clientSecret: $id.'_secret',
            chargeId: $this->instant ? 'ch_fake' : null,
        );
    }

    public function retrieveIntent(string $intentId): PaymentIntentResult
    {
        if ($this->shouldFail) {
            throw PaymentException::gatewayUnavailable();
        }

        return $this->intents[$intentId] ?? throw PaymentException::unknownIntent($intentId);
    }

    public function refund(string $intentId, int $amountMinor): string
    {
        if ($this->refundShouldFail) {
            throw PaymentException::refundFailed();
        }

        $this->refunds[] = ['intent' => $intentId, 'amount' => $amountMinor];

        return 're_fake_'.count($this->refunds);
    }

    /*
    |--------------------------------------------------------------------------
    | Test controls
    |--------------------------------------------------------------------------
    */

    /** Model a provider that confirms out of band rather than synchronously. */
    public function asynchronous(): static
    {
        $this->instant = false;

        return $this;
    }

    /** Settle an intent after the fact, as a delayed provider would. */
    public function markSucceeded(string $intentId, ?int $amountMinor = null, string $chargeId = 'ch_fake'): void
    {
        $existing = $this->intents[$intentId];

        $this->intents[$intentId] = new PaymentIntentResult(
            id: $existing->id,
            amountMinor: $amountMinor ?? $existing->amountMinor,
            status: 'succeeded',
            clientSecret: $existing->clientSecret,
            chargeId: $chargeId,
        );
    }

    public function markCancelled(string $intentId): void
    {
        $existing = $this->intents[$intentId];

        $this->intents[$intentId] = new PaymentIntentResult(
            id: $existing->id,
            amountMinor: $existing->amountMinor,
            status: 'canceled',
            clientSecret: $existing->clientSecret,
        );
    }
}
