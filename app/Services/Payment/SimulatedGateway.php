<?php

namespace App\Services\Payment;

use App\Contracts\PaymentGateway;
use Illuminate\Support\Str;

/**
 * A gateway that pretends.
 *
 * Every top-up succeeds immediately, no card is involved, and no money leaves
 * anybody's account. It exists so the whole wallet economy -- top-ups,
 * bookings, owner earnings, withdrawals -- can be exercised end to end while
 * the question of which real provider to use is still open.
 *
 * WHAT IS AND IS NOT SIMULATED, because this distinction matters:
 *
 *   Simulated: the customer's money arriving. Nothing is charged; the balance
 *              appears because this class says it did.
 *
 *   REAL:      everything after that. The ledger, the holds, the capture to an
 *              owner, the refund tiers, the maturity window, the withdrawal
 *              debit -- all of it runs through the same WalletService code that
 *              would run against a live gateway, and reconciles the same way.
 *
 * So the accounting is genuinely being tested. Only the front door is fake, and
 * replacing it is a new class plus a binding in AppServiceProvider.
 *
 * Withdrawals were never going to be automated anyway: they settle as manual
 * bank transfers over local rails. That half of the loop is unaffected by this
 * class -- it is only the money-in leg that is standing in for a real provider.
 */
class SimulatedGateway implements PaymentGateway
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'Simulated payments';
    }

    /**
     * True, and this is the interesting part of the class. TopupService checks
     * it and credits the wallet inside `start()` rather than waiting for a
     * callback that will never come.
     */
    public function settlesInstantly(): bool
    {
        return true;
    }

    public function createIntent(int $amountMinor, array $metadata, string $idempotencyKey): PaymentIntentResult
    {
        return new PaymentIntentResult(
            id: 'sim_'.Str::lower(Str::random(20)),
            amountMinor: $amountMinor,
            status: 'succeeded',
            // No secret to hand a browser: there is no payment form to mount.
            clientSecret: null,
            chargeId: 'simch_'.Str::lower(Str::random(16)),
        );
    }

    /**
     * Nothing is stored gateway-side, so the only honest answer is that
     * whatever was created succeeded. The reconcile sweep therefore never finds
     * anything to fix, which is correct: a synchronous gateway cannot leave a
     * top-up stranded.
     */
    public function retrieveIntent(string $intentId): PaymentIntentResult
    {
        return new PaymentIntentResult(
            id: $intentId,
            amountMinor: 0,
            status: 'succeeded',
        );
    }

    /**
     * The wallet side of a refund is real -- the balance is debited through the
     * ledger by TreasuryService. This only stands in for the leg that would
     * push money back to a card.
     */
    public function refund(string $intentId, int $amountMinor): string
    {
        return 'simref_'.Str::lower(Str::random(16));
    }
}
