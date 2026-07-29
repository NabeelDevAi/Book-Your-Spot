<?php

namespace App\Http\Controllers\Admin;

use App\Enums\WithdrawalStatus;
use App\Exceptions\WithdrawalException;
use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Services\Wallet\WithdrawalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The payout queue.
 *
 * Every action here corresponds to something a human does in a banking app, so
 * the console's job is to show exactly what to type and to record what happened
 * afterwards. Nothing is automated: payouts to Pakistani bank accounts have
 * no card-processor path, so this was always going to be local rails.
 */
class WithdrawalController extends Controller
{
    public function __construct(private readonly WithdrawalService $withdrawals) {}

    public function index(Request $request): View
    {
        $status = $request->query('status', 'open');

        $query = Withdrawal::query()->with('owner', 'processor');

        $query = match ($status) {
            'all' => $query,
            'paid' => $query->where('status', WithdrawalStatus::Paid),
            default => $query->open(),
        };

        return view('admin.withdrawals.index', [
            'withdrawals' => $query->orderBy('requested_at')->paginate(25)->withQueryString(),
            'status' => $status,
            'openCount' => Withdrawal::open()->count(),
            'openTotalMinor' => (int) Withdrawal::open()->sum('amount_minor'),
        ]);
    }

    public function approve(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        return $this->run(
            fn () => $this->withdrawals->approve($withdrawal, $request->user()),
            'Marked as being paid. Make the transfer, then record the reference.',
        );
    }

    public function markPaid(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        $validated = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->run(
            fn () => $this->withdrawals->markPaid($withdrawal, $request->user(), $validated['external_reference'] ?? null),
            'Withdrawal marked as paid and the owner has been notified.',
        );
    }

    public function reject(Request $request, Withdrawal $withdrawal): RedirectResponse
    {
        // Mandatory: the owner's balance is about to move back for a reason
        // only the Admin knows, and that reason goes into the audit log.
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'failed' => ['nullable', 'boolean'],
        ]);

        return $this->run(
            fn () => $this->withdrawals->reverse(
                $withdrawal,
                $request->user(),
                $validated['reason'],
                failed: (bool) ($validated['failed'] ?? false),
            ),
            'Withdrawal reversed and the money returned to the owner\'s balance.',
        );
    }

    private function run(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (WithdrawalException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('admin.withdrawals.index')->with('success', $success);
    }
}
