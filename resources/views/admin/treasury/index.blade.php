<x-console-layout title="Treasury" context="admin">
    <x-ui.page-header
        title="Treasury"
        description="The float position and whether the books balance."
    />

    <div class="stack-8">
        {{-- Reconciliation first. If the books do not balance, nothing below is
             worth reading until somebody has worked out why. --}}
        @if ($reconciliation['balanced'])
            <x-ui.alert variant="success" title="Books balance">
                Every wallet agrees with its own ledger, and money in minus money out equals
                money held. Checked live, not cached.
            </x-ui.alert>
        @else
            <x-ui.alert variant="danger" title="The books do not balance — this is an incident">
                <ul style="margin: var(--space-2) 0 0; padding-left: var(--space-4);">
                    @if ($reconciliation['discrepancy_minor'] !== 0)
                        <li>
                            Money in minus money out is
                            {{ \App\Support\Money::pkrMinor($reconciliation['expected_minor']) }},
                            but wallets hold
                            {{ \App\Support\Money::pkrMinor($reconciliation['actual_minor']) }} —
                            a discrepancy of
                            <strong>{{ \App\Support\Money::pkrMinor($reconciliation['discrepancy_minor']) }}</strong>.
                        </li>
                    @endif
                    @if ($reconciliation['drifted_wallets'] > 0)
                        <li>{{ $reconciliation['drifted_wallets'] }} wallet(s) disagree with their own ledger.</li>
                    @endif
                    @if ($reconciliation['held_drift_minor'] !== 0)
                        <li>
                            Held funds differ from active holds by
                            {{ \App\Support\Money::pkrMinor($reconciliation['held_drift_minor']) }}.
                        </li>
                    @endif
                </ul>
            </x-ui.alert>
        @endif

        {{-- The liability figure leads. It is the money that has to be sitting
             in the bank account, and it is not revenue. --}}
        <div class="stack-3">
            <h2 class="h3">What we are holding</h2>

            <div class="treasury-grid">
                <x-ui.stat
                    label="Total liability"
                    :value="\App\Support\Money::pkrMinor($float['total_liability_minor'])"
                    meta="Owed to customers and owners — must be backed by cash"
                    tone="attention"
                />
                <x-ui.stat
                    label="Customer balances"
                    :value="\App\Support\Money::pkrMinor($float['customer_balances_minor'])"
                    :meta="\App\Support\Money::pkrMinor($float['held_minor']).' of it held for pending requests'"
                />
                <x-ui.stat
                    label="Owner — withdrawable"
                    :value="\App\Support\Money::pkrMinor($float['owner_available_minor'])"
                    meta="Cleared, can be cashed out now"
                />
                <x-ui.stat
                    label="Owner — clearing"
                    :value="\App\Support\Money::pkrMinor($float['owner_pending_minor'])"
                    meta="Inside the dispute window"
                />
                <x-ui.stat
                    label="Withdrawals in flight"
                    :value="\App\Support\Money::pkrMinor($float['withdrawals_in_flight_minor'])"
                    meta="Debited, not yet transferred"
                    :href="route('admin.withdrawals.index')"
                />
            </div>
        </div>

        <div class="stack-3">
            <h2 class="h3">Lifetime</h2>

            <div class="grid-2">
                <x-ui.stat
                    label="Topped up"
                    :value="\App\Support\Money::pkrMinor($float['lifetime_topups_minor'])"
                    meta="Money that has entered the platform"
                />
                <x-ui.stat
                    label="Paid out"
                    :value="\App\Support\Money::pkrMinor($float['lifetime_payouts_minor'])"
                    meta="Money transferred to owners' banks"
                />
            </div>
        </div>

        <x-ui.card title="Finding a wallet">
            <p class="text-muted">
                Wallets are reached from the account they belong to — open
                <a href="{{ route('admin.users.index') }}" class="link">Users &amp; owners</a>
                and follow the wallet link, or click through from a
                <a href="{{ route('admin.withdrawals.index') }}" class="link">withdrawal</a>.
            </p>
        </x-ui.card>
    </div>
</x-console-layout>
