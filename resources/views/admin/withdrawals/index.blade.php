<x-console-layout title="Withdrawals" context="admin">
    <x-ui.page-header
        title="Withdrawals"
        description="Money leaving the platform. Each row is a bank transfer somebody has to make."
    />

    <div class="stack-6">
        <div class="grid-2">
            <x-ui.stat
                label="Awaiting payment"
                :value="$openCount"
                :meta="\App\Support\Money::pkrMinor($openTotalMinor).' to transfer'"
                :tone="$openCount > 0 ? 'attention' : null"
            />
            <x-ui.stat
                label="Paid all time"
                :value="\App\Support\Money::pkrMinor((int) \App\Models\Withdrawal::where('status', 'paid')->sum('amount_minor'))"
                meta="Settled to owners"
            />
        </div>

        <div class="cluster-2">
            @foreach (['open' => 'Awaiting payment', 'paid' => 'Paid', 'all' => 'All'] as $key => $label)
                <a href="{{ route('admin.withdrawals.index', ['status' => $key]) }}"
                   class="btn btn-sm {{ $status === $key ? 'btn-primary' : 'btn-secondary' }}">{{ $label }}</a>
            @endforeach
        </div>

        @if ($withdrawals->isEmpty())
            <x-ui.card>
                <x-ui.empty-state icon="wallet" title="Nothing to pay out">
                    Withdrawal requests from owners appear here.
                </x-ui.empty-state>
            </x-ui.card>
        @else
            <div class="stack-4">
                @foreach ($withdrawals as $withdrawal)
                    <x-ui.card>
                        <div class="split split-start">
                            <div class="stack-2 flex-1">
                                <div class="cluster-2">
                                    <span class="mono font-semibold">{{ $withdrawal->reference }}</span>
                                    <x-ui.badge :variant="$withdrawal->status->badge()">
                                        {{ $withdrawal->status->label() }}
                                    </x-ui.badge>
                                </div>

                                <div class="text-muted">
                                    {{ $withdrawal->owner->name }} ·
                                    <a href="{{ route('admin.treasury.wallet', $withdrawal->owner) }}" class="link">
                                        view wallet
                                    </a>
                                </div>

                                {{-- Exactly what an admin types into a banking
                                     app. The snapshot is the authority, not the
                                     owner's current payout account. --}}
                                <dl class="payout-detail">
                                    <div><dt>Bank</dt><dd>{{ $withdrawal->bankName() }}</dd></div>
                                    <div><dt>Title</dt><dd>{{ $withdrawal->accountTitle() }}</dd></div>
                                    <div><dt>Account</dt><dd class="mono">{{ $withdrawal->accountNumber() }}</dd></div>
                                    @if ($withdrawal->payout_account_snapshot['iban'] ?? null)
                                        <div><dt>IBAN</dt><dd class="mono">{{ $withdrawal->payout_account_snapshot['iban'] }}</dd></div>
                                    @endif
                                </dl>

                                <div class="text-xs text-muted">
                                    Requested {{ $withdrawal->requested_at->diffForHumans() }}
                                    @if ($withdrawal->processor)
                                        · last handled by {{ $withdrawal->processor->name }}
                                    @endif
                                </div>

                                @if ($withdrawal->external_reference)
                                    <div class="text-xs">Bank reference <span class="mono">{{ $withdrawal->external_reference }}</span></div>
                                @endif

                                @if ($withdrawal->failure_reason)
                                    <div class="text-sm text-danger">{{ $withdrawal->failure_reason }}</div>
                                @endif
                            </div>

                            <div class="text-right stack-3">
                                <div class="h3">{{ $withdrawal->amountLabel() }}</div>

                                @if ($withdrawal->status->isOpen())
                                    <div class="stack-2">
                                        @if ($withdrawal->status === \App\Enums\WithdrawalStatus::Requested)
                                            <form method="POST" action="{{ route('admin.withdrawals.approve', $withdrawal) }}">
                                                @csrf
                                                <x-ui.button type="submit" variant="secondary" size="sm" block>
                                                    Start transfer
                                                </x-ui.button>
                                            </form>
                                        @endif

                                        <form method="POST" action="{{ route('admin.withdrawals.paid', $withdrawal) }}"
                                              class="stack-2">
                                            @csrf
                                            <x-ui.input
                                                name="external_reference"
                                                placeholder="Bank reference"
                                            />
                                            <x-ui.button type="submit" variant="primary" size="sm" block>
                                                Mark paid
                                            </x-ui.button>
                                        </form>

                                        <x-ui.button
                                            variant="ghost" size="sm" type="button" block
                                            data-modal-open="reject-{{ $withdrawal->id }}"
                                        >Reject</x-ui.button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </x-ui.card>

                    @if ($withdrawal->status->isOpen())
                        <x-ui.modal :id="'reject-'.$withdrawal->id" title="Reject withdrawal {{ $withdrawal->reference }}">
                            <form method="POST" action="{{ route('admin.withdrawals.reject', $withdrawal) }}" class="stack-4">
                                @csrf

                                <p class="text-muted">
                                    {{ $withdrawal->amountLabel() }} goes straight back to
                                    {{ $withdrawal->owner->name }}'s balance.
                                </p>

                                <x-ui.field label="Reason" name="reason" hint="Sent to the owner and recorded in the audit log." required>
                                    <x-ui.textarea name="reason" rows="3" />
                                </x-ui.field>

                                <label class="cluster-2 text-sm">
                                    <input type="checkbox" name="failed" value="1">
                                    The transfer was attempted and bounced
                                </label>

                                <x-ui.button type="submit" variant="danger">Reject and return funds</x-ui.button>
                            </form>
                        </x-ui.modal>
                    @endif
                @endforeach

                {{ $withdrawals->links() }}
            </div>
        @endif
    </div>
</x-console-layout>
