<?php

namespace App\Services\Payment;

use App\Models\Topup;

/**
 * The result of starting a top-up: the persisted attempt plus the gateway
 * intent the browser needs to finish it.
 *
 * A pair rather than an extra column on Topup, because the client secret is a
 * short-lived credential for one attempt and has no business being stored.
 */
final readonly class StartedTopup
{
    public function __construct(
        public Topup $topup,
        public PaymentIntentResult $intent,
    ) {}

    public function clientSecret(): ?string
    {
        return $this->intent->clientSecret;
    }
}
