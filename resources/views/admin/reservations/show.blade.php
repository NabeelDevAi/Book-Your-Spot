<x-console-layout :title="$reservation->reference" context="admin">
    <x-ui.page-header :title="'Reservation '.$reservation->reference">
        <x-slot:breadcrumb>
            <a href="{{ route('admin.reservations.index') }}" class="link-muted">Reservations</a>
            <span class="breadcrumb-sep">/</span>
            <span>{{ $reservation->reference }}</span>
        </x-slot:breadcrumb>

        <x-slot:actions>
            <x-ui.badge :status="$reservation->status->value">{{ $reservation->status->label() }}</x-ui.badge>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="layout-with-aside">
        <div class="stack-6">
            <x-ui.card title="Booking">
                <dl class="detail-list">
                    <dt>Customer</dt>
                    <dd>
                        <a href="{{ route('admin.users.show', $reservation->user) }}" class="link">
                            {{ $reservation->user->name }}
                        </a>
                        <div class="text-sm text-muted">
                            {{ $reservation->user->email }} · {{ $reservation->user->phone }}
                            @if ($reservation->user->no_show_count > 0)
                                · {{ $reservation->user->no_show_count }} no-shows on record
                            @endif
                        </div>
                    </dd>

                    <dt>Venue</dt>
                    <dd>
                        <a href="{{ route('admin.businesses.show', $reservation->business) }}" class="link">
                            {{ $reservation->business->name }}
                        </a>
                        <div class="text-sm text-muted">
                            Owner: {{ $reservation->business->owner->name }} · {{ $reservation->business->contact_number }}
                        </div>
                    </dd>

                    <dt>Spot</dt>
                    <dd>{{ $reservation->spot->name }} ({{ $reservation->spot->businessGame->game->name }})</dd>
                    <dt>When</dt>
                    <dd>{{ $reservation->dateLabel() }}, {{ $reservation->timeRangeLabel() }} · {{ $reservation->durationLabel() }}</dd>
                    <dt>Price</dt>
                    <dd>
                        {{ $reservation->totalPriceLabel() }}
                        <div class="text-sm text-muted">
                            {{ \App\Support\Money::pkr($reservation->price_amount_snapshot) }}
                            per {{ \App\Support\Money::duration($reservation->price_unit_minutes_snapshot) }} at booking time
                        </div>
                    </dd>
                    <dt>Requested</dt><dd>{{ $reservation->requested_at?->format('j M Y, g:i A') }}</dd>
                    <dt>Deadline</dt><dd>{{ $reservation->response_deadline->format('j M Y, g:i A') }}</dd>

                    @if ($reservation->responded_at)
                        <dt>Answered</dt>
                        <dd>
                            {{ $reservation->responded_at->format('j M Y, g:i A') }}
                            @if ($reservation->responder)by {{ $reservation->responder->name }}@endif
                        </dd>
                    @endif

                    @if ($reservation->rejection_reason_code)
                        <dt>Reason</dt>
                        <dd>
                            {{ $reservation->rejection_reason_code->label() }}
                            @if ($reservation->rejection_reason_text)
                                <div class="text-sm text-muted">{{ $reservation->rejection_reason_text }}</div>
                            @endif
                        </dd>
                    @endif

                    @if ($reservation->cancelled_at)
                        <dt>Cancelled</dt>
                        <dd>
                            {{ $reservation->cancelled_at->format('j M Y, g:i A') }}
                            by {{ $reservation->cancelled_by_role?->label() }}
                            @if ($reservation->is_late_cancellation)
                                <x-ui.badge variant="warning">Late</x-ui.badge>
                            @endif
                            @if ($reservation->cancellation_reason)
                                <div class="text-sm text-muted">{{ $reservation->cancellation_reason }}</div>
                            @endif
                        </dd>
                    @endif
                </dl>
            </x-ui.card>

            @if ($reservation->conflicts->isNotEmpty())
                <x-ui.card title="Clashes">
                    <div class="stack-3">
                        @foreach ($reservation->conflicts as $conflict)
                            <div class="split split-start">
                                <div>
                                    <div class="font-medium">{{ $conflict->source_type->label() }}</div>
                                    @if ($conflict->resolution_note)
                                        <div class="text-sm text-muted">{{ $conflict->resolution_note }}</div>
                                    @endif
                                </div>
                                <div class="text-right">
                                    <x-ui.badge :variant="$conflict->status->badge()">
                                        {{ $conflict->status->label() }}
                                    </x-ui.badge>
                                    @if ($conflict->resolution)
                                        <div class="text-xs text-muted">{{ $conflict->resolution->label() }}</div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-ui.card>
            @endif
        </div>

        <aside class="aside-sticky">
            {{-- SRS 9.20: the complete story of this booking, including the
                 System entries the scheduler wrote. --}}
            <x-ui.card title="Audit trail">
                @if ($auditTrail->isEmpty())
                    <p class="text-muted text-sm">No recorded actions.</p>
                @else
                    <div class="stack-3">
                        @foreach ($auditTrail as $log)
                            <div>
                                <div class="text-sm font-medium">
                                    {{ str_replace(['reservation.', '_'], ['', ' '], $log->action) }}
                                </div>
                                <div class="text-xs text-muted">
                                    {{ $log->actorLabel() }} · {{ $log->created_at->format('j M, g:i A') }}
                                </div>
                                @if ($log->reason)
                                    <div class="text-xs text-secondary">{{ $log->reason }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        </aside>
    </div>
</x-console-layout>
