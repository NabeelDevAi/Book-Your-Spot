<x-console-layout title="Clashes" context="owner">
    <x-ui.page-header
        title="Clashes"
        description="Bookings affected by a block or a spot you took out of service."
    />

    {{-- SRS 9.6 in plain language. The whole reason this queue exists is that
         silently cancelling a confirmed customer is the failure mode to avoid. --}}
    <x-ui.alert variant="info" style="margin-bottom: var(--space-5);">
        These bookings have <strong>not</strong> been cancelled. Call the customer, agree what
        happens, and record the outcome here so everyone knows where they stand.
    </x-ui.alert>

    <x-ui.card flush>
        @if ($conflicts->isEmpty())
            <x-ui.empty-state icon="check-circle" title="No clashes">
                When you block a spot or take one out of service while it has bookings,
                those bookings appear here for you to resolve.
            </x-ui.empty-state>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Booking</th>
                            <th>Customer</th>
                            <th>When</th>
                            <th>Caused by</th>
                            <th>Status</th>
                            <th class="cell-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($conflicts as $conflict)
                            @php
                                $reservation = $conflict->reservation;
                            @endphp
                            <tr class="{{ $conflict->isOpen() ? 'is-attention' : 'is-muted' }}">
                                <td>
                                    <a href="{{ route('owner.reservations.show', $reservation) }}" class="cell-primary link mono">
                                        {{ $reservation->reference }}
                                    </a>
                                    <div class="cell-secondary">
                                        {{ $reservation->spot->name }} · {{ $reservation->business->name }}
                                    </div>
                                </td>

                                <td>
                                    <div class="cell-primary">{{ $reservation->customerDisplayName() }}</div>
                                    <div class="cell-secondary">{{ $reservation->customerDisplayPhone() }}</div>
                                </td>

                                <td>
                                    <div class="cell-primary">{{ $reservation->dateLabel() }}</div>
                                    <div class="cell-secondary">{{ $reservation->timeRangeLabel() }}</div>
                                </td>

                                <td>
                                    {{ $conflict->source_type->label() }}
                                    <div class="cell-secondary">
                                        {{ $conflict->created_at->diffForHumans() }}
                                    </div>
                                </td>

                                <td>
                                    <x-ui.badge :variant="$conflict->status->badge()">
                                        {{ $conflict->status->label() }}
                                    </x-ui.badge>

                                    @if ($conflict->resolution)
                                        <div class="cell-secondary">{{ $conflict->resolution->label() }}</div>
                                    @endif
                                </td>

                                <td class="cell-actions">
                                    @if ($conflict->isOpen())
                                        <x-ui.button
                                            variant="primary" size="sm" type="button"
                                            data-modal-open="resolve-{{ $conflict->id }}"
                                        >Resolve</x-ui.button>
                                    @else
                                        <span class="text-muted text-sm">
                                            {{ $conflict->resolved_at?->format('j M Y') }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="padding: 0 var(--space-5) var(--space-4);">{{ $conflicts->links() }}</div>
        @endif
    </x-ui.card>

    @foreach ($conflicts->where('status', \App\Enums\ConflictStatus::Open) as $conflict)
        <x-ui.modal
            id="resolve-{{ $conflict->id }}"
            title="How was this resolved?"
            subtitle="{{ $conflict->reservation->reference }} — {{ $conflict->reservation->customerDisplayName() }}, {{ $conflict->reservation->dateLabel() }}"
        >
            <form method="POST" action="{{ route('owner.conflicts.resolve', $conflict) }}" class="form" id="resolve-form-{{ $conflict->id }}">
                @csrf

                <x-ui.alert variant="neutral">
                    Call {{ $conflict->reservation->customerDisplayName() }} on
                    <strong>{{ $conflict->reservation->customerDisplayPhone() }}</strong> before recording an outcome.
                </x-ui.alert>

                <fieldset>
                    <legend class="field-label" style="margin-bottom: var(--space-2);">Outcome</legend>
                    <div class="stack-2">
                        @foreach ($resolutions as $resolution)
                            <label class="choice-card">
                                <input type="radio" name="resolution" value="{{ $resolution->value }}" @checked($loop->first)>
                                <span>
                                    <span class="choice-card-title">{{ $resolution->label() }}</span>
                                    <span class="cell-secondary">{{ $resolution->description() }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <x-ui.field label="Note" name="note" hint="Optional — kept on the record.">
                    <x-ui.textarea name="note" rows="2" placeholder="Spoke to the customer, moved them to 9pm." />
                </x-ui.field>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" type="button" data-modal-close>Back</x-ui.button>
                <x-ui.button variant="primary" type="submit" form="resolve-form-{{ $conflict->id }}">
                    Record outcome
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endforeach
</x-console-layout>
