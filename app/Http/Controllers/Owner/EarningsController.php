<?php

namespace App\Http\Controllers\Owner;

use App\Exceptions\WithdrawalException;
use App\Http\Controllers\Controller;
use App\Models\PayoutAccount;
use App\Models\Reservation;
use App\Models\Withdrawal;
use App\Services\Wallet\WalletService;
use App\Services\Wallet\WithdrawalService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Owner's money: what they have earned, what has cleared, and getting it
 * into their bank.
 *
 * The page leads with the split between available and clearing, because "why
 * can I only withdraw part of this?" is the question the maturity window
 * inevitably produces.
 */
class EarningsController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly WithdrawalService $withdrawals,
    ) {}

    public function index(Request $request): View
    {
        $owner = $request->user();
        $wallet = $this->wallets->for($owner);

        return view('owner.earnings', [
            'wallet' => $wallet,
            'transactions' => $wallet->transactions()->latestFirst()->paginate(20),
            'accounts' => PayoutAccount::where('owner_id', $owner->id)->latest()->get(),
            'withdrawals' => Withdrawal::forOwner($owner->id)->latest()->limit(10)->get(),
            'minWithdrawal' => (int) config('wallet.min_withdrawal_minor'),
            'maturityHours' => (int) config('wallet.maturity_hours'),

            // What is still clearing, and when the earliest of it lands. An
            // Owner staring at a pending figure wants a date, not a policy.
            'nextMaturing' => Reservation::query()
                ->whereIn('business_id', $owner->businesses()->pluck('id'))
                ->whereNull('earnings_matured_at')
                ->whereNotNull('amount_paid_minor')
                ->orderBy('end_datetime')
                ->first(),
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'bank_name' => ['required', 'string', 'max:120'],
            'account_title' => ['required', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'max:64'],
            'iban' => ['nullable', 'string', 'max:34'],
        ]);

        PayoutAccount::create($validated + ['owner_id' => $request->user()->id]);

        return redirect()
            ->route('owner.earnings.index')
            ->with('success', 'Bank account saved. You can now request a withdrawal.');
    }

    public function destroyAccount(Request $request, PayoutAccount $account): RedirectResponse
    {
        abort_unless($account->owner_id === $request->user()->id, 403);

        $account->delete();

        return redirect()->route('owner.earnings.index')->with('success', 'Bank account removed.');
    }

    public function requestWithdrawal(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'payout_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $account = PayoutAccount::find($validated['payout_account_id']);

        if ($account === null) {
            return back()->with('error', WithdrawalException::noPayoutAccount()->getMessage());
        }

        try {
            $withdrawal = $this->withdrawals->request(
                $request->user(),
                $account,
                Money::toMinor((string) $validated['amount']),
            );
        } catch (WithdrawalException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('owner.earnings.index')
            ->with('success', $withdrawal->amountLabel().' requested. Reference '.$withdrawal->reference.'.');
    }
}
