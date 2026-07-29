<x-console-layout :title="$subject->name.' — wallet'" context="admin">
    <x-ui.page-header
        :title="$subject->name.' — wallet'"
        :description="$subject->email.' · '.$subject->role->label()"
    >
        <x-slot:actions>
            <x-ui.button :href="route('admin.users.show', $subject)" variant="secondary">View account</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="stack-8">
        @unless ($reconciles)
            <x-ui.alert variant="danger" title="This wallet disagrees with its own ledger">
                The cached balance does not equal the sum of its transactions. Do not adjust it until
                somebody has worked out which one is wrong — an adjustment on top of a drift hides
                the original cause.
            </x-ui.alert>
        @endunless

        @if ($wallet->isFrozen())
            <x-ui.alert variant="warning" title="Frozen">
                {{ $wallet->frozen_reason ?: 'No reason recorded.' }}
                @if ($wallet->frozen_at)
                    <span class="text-muted">— {{ $wallet->frozen_at->diffForHumans() }}</span>
                @endif
            </x-ui.alert>
        @endif

        <div class="treasury-grid">
            <x-ui.stat label="Balance" :value="\App\Support\Money::pkrMinor($wallet->balance_minor)"
                       :tone="$wallet->balance_minor < 0 ? 'danger' : null"
                       :meta="$wallet->balance_minor < 0 ? 'Negative — chargeback recovery' : 'Settled funds'" />
            <x-ui.stat label="Held" :value="\App\Support\Money::pkrMinor($wallet->held_minor)" meta="Committed to pending requests" />
            <x-ui.stat label="Available" :value="\App\Support\Money::pkrMinor($wallet->availableMinor())" meta="Spendable now" />
            <x-ui.stat label="Pending earnings" :value="\App\Support\Money::pkrMinor($wallet->pending_minor)" meta="Owner side, still clearing" />
        </div>

        <div class="grid-2">
            {{-- Mandatory reason, by design. An unexplained hand-moved balance
                 is the entry nobody can account for six months later. --}}
            <x-ui.card title="Adjust balance">
                <form method="POST" action="{{ route('admin.treasury.adjust', $subject) }}" class="stack-3">
                    @csrf

                    <x-ui.field label="Direction" name="direction">
                        <x-ui.select name="direction" :options="['credit' => 'Credit (give money)', 'debit' => 'Debit (take money)']" />
                    </x-ui.field>

                    <x-ui.field label="Bucket" name="bucket" hint="Pending is owner earnings that have not cleared.">
                        <x-ui.select name="bucket" :options="['balance' => 'Balance', 'pending' => 'Pending earnings']" />
                    </x-ui.field>

                    <x-ui.field label="Amount (PKR)" name="amount">
                        <x-ui.input type="number" name="amount" prefix="Rs." step="1" min="1" />
                    </x-ui.field>

                    <x-ui.field label="Reason" name="reason" hint="Recorded in the audit log against your account." required>
                        <x-ui.textarea name="reason" rows="2" />
                    </x-ui.field>

                    <x-ui.button type="submit" variant="danger-outline">Record adjustment</x-ui.button>
                </form>
            </x-ui.card>

            <x-ui.card title="Freeze">
                @if ($wallet->isFrozen())
                    <div class="stack-3">
                        <p class="text-muted">
                            Nothing can be spent, booked or withdrawn from this wallet. Releasing it
                            restores normal use immediately.
                        </p>

                        <form method="POST" action="{{ route('admin.treasury.unfreeze', $subject) }}">
                            @csrf
                            <x-ui.button type="submit" variant="primary">Release wallet</x-ui.button>
                        </form>
                    </div>
                @else
                    <form method="POST" action="{{ route('admin.treasury.freeze', $subject) }}" class="stack-3">
                        @csrf

                        <p class="text-muted text-sm">
                            Stops all spending immediately. Use for a chargeback or suspected fraud.
                            Refunds and hold releases still work — a freeze stops money going out,
                            not coming back.
                        </p>

                        <x-ui.field label="Reason" name="reason" required>
                            <x-ui.textarea name="reason" rows="2" />
                        </x-ui.field>

                        <x-ui.button type="submit" variant="danger">Freeze wallet</x-ui.button>
                    </form>
                @endif
            </x-ui.card>
        </div>

        {{-- Top-ups, with refund-to-source --}}
        @if ($topups->isNotEmpty())
            <div class="stack-3">
                <h2 class="h3">Top-ups</h2>

                <x-ui.card flush>
                    <div class="table-wrap">
                        <table class="table table-compact">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th class="cell-numeric">Amount</th>
                                    <th>Status</th>
                                    <th>Reference</th>
                                    <th class="cell-actions">Refund</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($topups as $topup)
                                    <tr>
                                        <td class="cell-tight text-muted">{{ $topup->created_at->format('j M Y, g:ia') }}</td>
                                        <td class="cell-numeric mono">{{ $topup->amount() }}</td>
                                        <td><x-ui.badge :variant="$topup->status->badge()">{{ $topup->status->label() }}</x-ui.badge></td>
                                        <td class="mono text-xs text-muted">{{ $topup->gateway_reference }}</td>
                                        <td class="cell-actions">
                                            @if ($topup->status->hasCredited())
                                                <x-ui.button
                                                    variant="ghost" size="sm" type="button"
                                                    data-modal-open="refund-{{ $topup->id }}"
                                                >Refund</x-ui.button>

                                                {{-- No gateway means no dispute
                                                     callback, so a chargeback is
                                                     entered by hand. --}}
                                                <x-ui.button
                                                    variant="ghost" size="sm" type="button"
                                                    data-modal-open="chargeback-{{ $topup->id }}"
                                                >Chargeback</x-ui.button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>

                @foreach ($topups->where('status', \App\Enums\TopupStatus::Succeeded) as $topup)
                    <x-ui.modal :id="'refund-'.$topup->id" title="Refund {{ $topup->amount() }} to card">
                        <form method="POST" action="{{ route('admin.treasury.refund', $topup) }}" class="stack-4">
                            @csrf

                            <p class="text-muted">
                                The amount is taken out of this wallet and sent back to the original card.
                                It fails if the customer has already spent it — that is deliberate, because
                                refunding money that is no longer there would create it.
                            </p>

                            <x-ui.field label="Reason" name="reason" required>
                                <x-ui.textarea name="reason" rows="2" />
                            </x-ui.field>

                            <x-ui.button type="submit" variant="danger">Send refund</x-ui.button>
                        </form>
                    </x-ui.modal>

                    <x-ui.modal :id="'chargeback-'.$topup->id" title="Record a chargeback on {{ $topup->amount() }}">
                        <form method="POST" action="{{ route('admin.treasury.chargeback', $topup) }}" class="stack-4">
                            @csrf

                            <p class="text-muted">
                                Recovers the full amount and freezes the wallet. Unlike a refund this
                                succeeds even if the money has been spent — the balance goes negative,
                                because the funds are genuinely gone and pretending otherwise would
                                leave the ledger claiming money we do not have.
                            </p>

                            <x-ui.field label="Reason" name="reason" required>
                                <x-ui.textarea name="reason" rows="2" />
                            </x-ui.field>

                            <x-ui.button type="submit" variant="danger">Record chargeback</x-ui.button>
                        </form>
                    </x-ui.modal>
                @endforeach
            </div>
        @endif

        {{-- Active and recent holds --}}
        @if ($holds->isNotEmpty())
            <div class="stack-3">
                <h2 class="h3">Holds</h2>

                <x-ui.card flush>
                    <div class="table-wrap">
                        <table class="table table-compact">
                            <thead>
                                <tr>
                                    <th>Booking</th>
                                    <th class="cell-numeric">Amount</th>
                                    <th>Status</th>
                                    <th>Resolved</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($holds as $hold)
                                    <tr>
                                        <td class="mono">
                                            @if ($hold->reservation)
                                                <a href="{{ route('admin.reservations.show', $hold->reservation) }}" class="link">
                                                    {{ $hold->reservation->reference }}
                                                </a>
                                            @else
                                                #{{ $hold->reservation_id }}
                                            @endif
                                        </td>
                                        <td class="cell-numeric mono">{{ \App\Support\Money::pkrMinor($hold->amount_minor) }}</td>
                                        <td>
                                            {{ $hold->status->label() }}
                                            @if ($hold->released_reason)
                                                <span class="cell-secondary">{{ $hold->released_reason }}</span>
                                            @endif
                                        </td>
                                        <td class="cell-tight text-muted">
                                            {{ $hold->resolved_at?->format('j M Y, g:ia') ?? '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>
            </div>
        @endif

        {{-- The ledger itself --}}
        <div class="stack-3">
            <h2 class="h3">Ledger</h2>

            @if ($transactions->isEmpty())
                <x-ui.card>
                    <x-ui.empty-state icon="wallet" title="No movements">
                        Nothing has ever moved in this wallet.
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                <x-ui.card flush>
                    <div class="table-wrap">
                        <table class="table table-compact">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Bucket</th>
                                    <th>Linked to</th>
                                    <th class="cell-numeric">Amount</th>
                                    <th class="cell-numeric">After</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($transactions as $entry)
                                    <tr>
                                        <td class="cell-tight text-muted">{{ $entry->created_at->format('j M Y, g:ia') }}</td>
                                        <td>
                                            {{ $entry->type->label() }}
                                            @if ($entry->meta['reason'] ?? null)
                                                <div class="cell-secondary">{{ $entry->meta['reason'] }}</div>
                                            @endif
                                        </td>
                                        <td class="text-muted">{{ $entry->bucket->label() }}</td>
                                        <td class="text-xs text-muted">
                                            @if ($entry->reservation_id)
                                                Booking #{{ $entry->reservation_id }}
                                            @elseif ($entry->topup_id)
                                                Top-up #{{ $entry->topup_id }}
                                            @elseif ($entry->withdrawal_id)
                                                Withdrawal #{{ $entry->withdrawal_id }}
                                            @else
                                                —
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

                    <div style="padding: 0 var(--space-5) var(--space-4);">{{ $transactions->links() }}</div>
                </x-ui.card>
            @endif
        </div>
    </div>
</x-console-layout>
