<x-console-layout title="Earnings" context="owner">
    <x-ui.page-header
        title="Earnings"
        description="What you've made, what's cleared, and getting it into your bank."
    />

    <div class="stack-8">
        {{-- Available leads. "Why can I only withdraw part of this?" is the
             question the maturity window inevitably produces, so the clearing
             figure is given equal billing rather than hidden in a footnote. --}}
        <div class="earnings-summary">
            <x-ui.stat
                label="Available to withdraw"
                :value="\App\Support\Money::pkrMinor($wallet->balance_minor)"
                meta="Cleared and yours"
            />

            <x-ui.stat
                label="Still clearing"
                :value="\App\Support\Money::pkrMinor($wallet->pending_minor)"
                :meta="$nextMaturing
                    ? 'Earliest clears '.$nextMaturing->end_datetime->copy()->addHours($maturityHours)->diffForHumans()
                    : 'Nothing pending'"
                :tone="$wallet->pending_minor > 0 ? 'attention' : null"
            />

            <x-ui.stat
                label="Total earned"
                :value="\App\Support\Money::pkrMinor($wallet->totalOwnedMinor())"
                meta="Available plus clearing"
            />
        </div>

        @if ($wallet->pending_minor > 0)
            <x-ui.alert variant="info" title="Why some earnings are still clearing">
                A booking's payment stays with us for {{ \App\Support\Money::duration($maturityHours * 60) }}
                after it finishes, so a late cancellation or a dispute can still be settled fairly.
                After that it moves across on its own — you don't need to do anything.
            </x-ui.alert>
        @endif

        @if ($wallet->isFrozen())
            <x-ui.alert variant="danger" title="Payouts paused">
                {{ $wallet->frozen_reason ?: 'A payment issue is being reviewed.' }}
                Please get in touch — withdrawals are on hold until it's resolved.
            </x-ui.alert>
        @endif

        {{-- Withdraw --}}
        <div class="grid-2">
            <x-ui.card title="Withdraw">
                @if ($accounts->isEmpty())
                    <x-ui.empty-state icon="building" title="Add a bank account first">
                        We need somewhere to send the money before you can withdraw.
                    </x-ui.empty-state>
                @else
                    <form method="POST" action="{{ route('owner.earnings.withdraw') }}" class="stack-4">
                        @csrf

                        <x-ui.field label="Pay into" name="payout_account_id">
                            <x-ui.select name="payout_account_id">
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">
                                        {{ $account->summary() }} — {{ $account->account_title }}
                                    </option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field
                            label="Amount (PKR)"
                            name="amount"
                            :hint="'Minimum '.\App\Support\Money::pkrMinor($minWithdrawal).'. You have '.\App\Support\Money::pkrMinor($wallet->balance_minor).' available.'"
                        >
                            <x-ui.input
                                type="number" name="amount" prefix="Rs."
                                min="{{ $minWithdrawal / 100 }}"
                                max="{{ $wallet->balance_minor / 100 }}"
                                step="1"
                            />
                        </x-ui.field>

                        <x-ui.button
                            type="submit" variant="primary" icon="wallet"
                            :disabled="$wallet->isFrozen() || $wallet->balance_minor < $minWithdrawal"
                        >Request withdrawal</x-ui.button>

                        <p class="text-xs text-muted">
                            Transfers are made by hand and usually go out within one working day.
                            The money leaves your balance as soon as you request it.
                        </p>
                    </form>
                @endif
            </x-ui.card>

            <x-ui.card title="Bank accounts">
                <div class="stack-4">
                    @foreach ($accounts as $account)
                        <div class="split split-start payout-account">
                            <div>
                                <div class="font-medium">{{ $account->bank_name }}</div>
                                <div class="text-sm text-muted mono">{{ $account->maskedNumber() }}</div>
                                <div class="text-sm text-muted">{{ $account->account_title }}</div>
                                @if ($account->isVerified())
                                    <span class="text-xs text-success">Verified</span>
                                @endif
                            </div>

                            <form method="POST" action="{{ route('owner.earnings.accounts.destroy', $account) }}">
                                @csrf @method('DELETE')
                                <x-ui.button type="submit" variant="ghost" size="sm">Remove</x-ui.button>
                            </form>
                        </div>
                    @endforeach

                    <details>
                        <summary class="text-sm font-medium" style="cursor: pointer;">Add an account</summary>

                        <form method="POST" action="{{ route('owner.earnings.accounts.store') }}"
                              class="stack-3" style="margin-top: var(--space-4);">
                            @csrf

                            <x-ui.field label="Bank" name="bank_name">
                                <x-ui.input name="bank_name" placeholder="Meezan Bank" />
                            </x-ui.field>

                            <x-ui.field label="Account title" name="account_title" hint="Exactly as it appears on the account.">
                                <x-ui.input name="account_title" />
                            </x-ui.field>

                            <x-ui.field label="Account number" name="account_number">
                                <x-ui.input name="account_number" />
                            </x-ui.field>

                            <x-ui.field label="IBAN" name="iban" hint="Optional, but makes transfers land faster.">
                                <x-ui.input name="iban" placeholder="PK00XXXX0000000000000000" />
                            </x-ui.field>

                            <x-ui.button type="submit" variant="secondary">Save account</x-ui.button>
                        </form>
                    </details>
                </div>
            </x-ui.card>
        </div>

        {{-- Withdrawal history --}}
        @if ($withdrawals->isNotEmpty())
            <div class="stack-3">
                <h2 class="h3">Withdrawals</h2>

                <x-ui.card flush>
                    <div class="table-wrap">
                        <table class="table table-compact">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Requested</th>
                                    <th>To</th>
                                    <th class="cell-numeric">Amount</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($withdrawals as $withdrawal)
                                    <tr>
                                        <td class="mono">{{ $withdrawal->reference }}</td>
                                        <td class="cell-tight text-muted">
                                            {{ $withdrawal->requested_at->format('j M Y') }}
                                        </td>
                                        <td>{{ $withdrawal->bankName() }}</td>
                                        <td class="cell-numeric mono">{{ $withdrawal->amountLabel() }}</td>
                                        <td>
                                            <x-ui.badge :variant="$withdrawal->status->badge()">
                                                {{ $withdrawal->status->label() }}
                                            </x-ui.badge>
                                            @if ($withdrawal->failure_reason)
                                                <div class="cell-secondary text-danger">
                                                    {{ $withdrawal->failure_reason }}
                                                </div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>
            </div>
        @endif

        {{-- Ledger --}}
        <div class="stack-3">
            <h2 class="h3">Statement</h2>

            @if ($transactions->isEmpty())
                <x-ui.card>
                    <x-ui.empty-state icon="wallet" title="No earnings yet">
                        Once a booking at one of your venues is confirmed, it shows up here.
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                <x-ui.card flush>
                    <div class="table-wrap">
                        <table class="table table-compact">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Description</th>
                                    <th class="cell-numeric">Amount</th>
                                    <th class="cell-numeric">Running</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($transactions as $entry)
                                    <tr>
                                        <td class="cell-tight text-muted">
                                            {{ $entry->created_at->format('j M Y, g:ia') }}
                                        </td>
                                        <td>
                                            {{ $entry->type->label() }}
                                            <span class="cell-secondary">{{ $entry->bucket->label() }}</span>
                                        </td>
                                        <td class="cell-numeric mono {{ $entry->isCredit() ? 'text-success' : '' }}">
                                            {{ $entry->signedAmount() }}
                                        </td>
                                        <td class="cell-numeric mono text-muted">
                                            {{ \App\Support\Money::pkrMinor($entry->balance_after_minor) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div style="padding: 0 var(--space-5) var(--space-4);">{{ $transactions->links() }}</div>
                </x-ui.card>
            @endif
        </div>
    </div>
</x-console-layout>
