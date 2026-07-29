<?php

namespace App\Http\Controllers\Site;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartTopupRequest;
use App\Services\Payment\TopupService;
use App\Services\Wallet\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The customer's wallet: balance, statement, and adding money.
 *
 * Payments are simulated -- there is no card form and nothing is charged -- so
 * a top-up is a single form post that settles on the spot. The credit itself
 * still goes through TopupService and WalletService, so the ledger behaves
 * exactly as it will once a real provider is plugged in.
 */
class WalletController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly TopupService $topups,
        private readonly PaymentGateway $gateway,
    ) {}

    public function show(Request $request): View
    {
        $wallet = $this->wallets->for($request->user());

        return view('site.wallet', [
            'wallet' => $wallet,
            'transactions' => $wallet->transactions()->latestFirst()->paginate(20),
            'minTopup' => (int) config('wallet.min_topup_minor'),
            'maxTopup' => (int) config('wallet.max_topup_minor'),
            // Shown to the customer verbatim. Nobody should be able to add
            // money without noticing that it is not real money.
            'simulated' => $this->gateway->settlesInstantly(),
            'gatewayName' => $this->gateway->name(),
        ]);
    }

    public function topup(StartTopupRequest $request): RedirectResponse
    {
        try {
            $started = $this->topups->start($request->user(), $request->amountMinor());
        } catch (PaymentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        // A synchronous gateway has already credited the wallet by now. An
        // asynchronous one would not have, so say so rather than promising
        // money that has not arrived.
        return redirect()->route('wallet.show')->with(
            'success',
            $started->topup->status->hasCredited()
                ? $started->topup->amount().' added to your wallet.'
                : $started->topup->amount().' is being processed and will appear shortly.',
        );
    }
}
