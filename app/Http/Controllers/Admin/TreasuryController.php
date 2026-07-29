<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LedgerBucket;
use App\Exceptions\PaymentException;
use App\Exceptions\WalletException;
use App\Http\Controllers\Controller;
use App\Models\Topup;
use App\Models\User;
use App\Services\Admin\TreasuryService;
use App\Services\Wallet\WalletService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Platform money: the float position, reconciliation, and the levers for
 * putting a specific wallet right.
 */
class TreasuryController extends Controller
{
    public function __construct(
        private readonly TreasuryService $treasury,
        private readonly WalletService $wallets,
    ) {}

    /**
     * The float dashboard.
     *
     * Leads with total liability, because that is the number that has to be
     * backed by cash in the bank and the one nobody thinks about until it is
     * too late.
     */
    public function index(): View
    {
        return view('admin.treasury.index', [
            'float' => $this->treasury->float(),
            'reconciliation' => $this->treasury->reconcile(),
        ]);
    }

    /** One account's money, in full. */
    public function wallet(Request $request, User $user): View
    {
        $wallet = $this->wallets->for($user);

        return view('admin.treasury.wallet', [
            'subject' => $user,
            'wallet' => $wallet,
            'transactions' => $wallet->transactions()->latestFirst()->paginate(50),
            'holds' => $wallet->holds()->with('reservation')->latest()->limit(20)->get(),
            'topups' => Topup::where('user_id', $user->id)->latest()->limit(20)->get(),
            'reconciles' => $wallet->reconciles(),
        ]);
    }

    public function adjust(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'direction' => ['required', 'in:credit,debit'],
            'bucket' => ['required', 'in:balance,pending'],
            // Mandatory. An unexplained hand-moved balance is exactly the entry
            // nobody can account for six months later.
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->treasury->adjust(
                $user,
                Money::toMinor((string) $validated['amount']),
                credit: $validated['direction'] === 'credit',
                reason: $validated['reason'],
                admin: $request->user(),
                bucket: LedgerBucket::from($validated['bucket']),
            );
        } catch (WalletException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.treasury.wallet', $user)
            ->with('success', 'Adjustment recorded.');
    }

    /**
     * Record a disputed payment by hand.
     *
     * With no live gateway there is no dispute callback, so this is the way a
     * chargeback enters the system. The recovery itself is the same code a real
     * provider's webhook will drive: debit the wallet -- below zero if the
     * money is already spent -- and freeze it.
     */
    public function chargeback(Request $request, Topup $topup): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->treasury->applyChargeback($topup, $validated['reason']);

        return redirect()
            ->route('admin.treasury.wallet', $topup->user_id)
            ->with('success', 'Chargeback recorded and the wallet frozen.');
    }

    public function freeze(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->treasury->freeze($user, $validated['reason'], $request->user());

        return redirect()
            ->route('admin.treasury.wallet', $user)
            ->with('success', 'Wallet frozen. No money can move until it is released.');
    }

    public function unfreeze(Request $request, User $user): RedirectResponse
    {
        $this->treasury->unfreeze($user, $request->user());

        return redirect()->route('admin.treasury.wallet', $user)->with('success', 'Wallet released.');
    }

    /**
     * Send money back to the card it came from.
     *
     * The only route by which money leaves a customer's wallet outward, and
     * deliberately Admin-only: a self-serve version would make the wallet
     * withdrawable, which is the line this design does not cross.
     */
    public function refundTopup(Request $request, Topup $topup): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->treasury->refundTopupToSource($topup, $validated['reason'], $request->user());
        } catch (PaymentException|WalletException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.treasury.wallet', $topup->user_id)
            ->with('success', 'Refund sent back to the original card.');
    }
}
