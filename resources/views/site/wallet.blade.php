<x-app-layout title="My wallet">
    <x-ui.page-header
        title="My wallet"
        description="Top up here, then pay for bookings straight from your balance."
    />

    <div class="stack-8">
        {{-- Available, not balance. Money held against a pending request is
             already spoken for, and showing the gross figure would promise a
             customer money they cannot actually spend. --}}
        <div class="wallet-summary">
            <x-ui.stat
                label="Available to spend"
                :value="\App\Support\Money::pkrMinor($wallet->availableMinor())"
                :meta="$wallet->held_minor > 0
                    ? \App\Support\Money::pkrMinor($wallet->held_minor).' held for pending requests'
                    : 'Nothing on hold'"
            />

            <x-ui.stat
                label="Total balance"
                :value="\App\Support\Money::pkrMinor($wallet->balance_minor)"
                meta="Including anything on hold"
            />
        </div>

        @if ($wallet->isFrozen())
            <x-ui.alert variant="danger" title="Wallet frozen">
                This wallet is on hold while a payment issue is reviewed. You can't top up or book
                until it's resolved — please get in touch.
            </x-ui.alert>
        @else
            <x-ui.card>
                <div class="stack-4">
                    <div class="stack-1">
                        <h2 class="h3">Add money</h2>
                        <p class="text-muted text-sm">
                            Between {{ \App\Support\Money::pkrMinor($minTopup) }} and
                            {{ \App\Support\Money::pkrMinor($maxTopup) }} per top-up.
                        </p>
                    </div>

                    @if ($simulated)
                        {{-- Stated plainly and never hidden. Somebody must not be
                             able to add money without realising it is not real. --}}
                        <x-ui.alert variant="info" :icon="false" title="Test mode">
                            Payments aren't live yet — {{ $gatewayName }} credits your balance
                            instantly and no card is charged. Everything after that (bookings,
                            refunds, venue payouts) behaves exactly as it will with real money.
                        </x-ui.alert>
                    @endif

                    <form method="POST" action="{{ route('wallet.topup') }}" class="stack-4">
                        @csrf

                        {{-- Presets cover the common cases; the field stays
                             free-form because somebody always needs exactly
                             enough for one specific court. --}}
                        <div class="cluster-2" role="group" aria-label="Quick amounts">
                            @foreach ([500, 1000, 2000, 5000] as $preset)
                                <button type="button" class="btn btn-secondary btn-sm" data-topup-preset="{{ $preset }}">
                                    Rs. {{ number_format($preset) }}
                                </button>
                            @endforeach
                        </div>

                        <x-ui.field label="Amount (PKR)" for="topup-amount" name="amount">
                            <x-ui.input
                                type="number" id="topup-amount" name="amount" prefix="Rs."
                                min="{{ $minTopup / 100 }}" max="{{ $maxTopup / 100 }}" step="1"
                                placeholder="1000" autocomplete="off"
                            />
                        </x-ui.field>

                        <x-ui.button type="submit" variant="primary" icon="wallet">
                            {{ $simulated ? 'Add to balance' : 'Continue to payment' }}
                        </x-ui.button>
                    </form>
                </div>
            </x-ui.card>
        @endif

        <div class="stack-3">
            <h2 class="h3">Statement</h2>

            @if ($transactions->isEmpty())
                <x-ui.card>
                    <x-ui.empty-state icon="wallet" title="Nothing here yet">
                        Once you top up or pay for a booking, every movement shows up here.
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
                                    <th class="cell-numeric">Balance</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($transactions as $entry)
                                    <tr>
                                        <td class="cell-tight text-muted">
                                            {{ $entry->created_at->format('j M Y, g:ia') }}
                                        </td>
                                        <td>
                                            <span class="cluster-1">
                                                <x-ui.icon
                                                    :name="$entry->isCredit() ? 'arrow-down-left' : 'arrow-up-right'"
                                                    :size="14"
                                                />
                                                {{ $entry->type->label() }}
                                            </span>
                                            @if ($entry->reservation_id)
                                                <span class="text-xs text-muted">
                                                    · booking #{{ $entry->reservation_id }}
                                                </span>
                                            @endif
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
                </x-ui.card>

                {{ $transactions->links() }}
            @endif
        </div>
    </div>

    @push('scripts')
        <script>
            // Presets only. There is no payment form to orchestrate: the
            // amount posts straight to the server, which settles it.
            document.querySelectorAll('[data-topup-preset]').forEach((button) => {
                button.addEventListener('click', () => {
                    const field = document.getElementById('topup-amount');
                    field.value = button.dataset.topupPreset;
                    field.focus();
                });
            });
        </script>
    @endpush
</x-app-layout>
