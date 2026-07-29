<x-app-layout title="Booking {{ $reservation->reference }}">
    <div class="container-narrow">
        <x-ui.page-header :title="'Booking '.$reservation->reference">
            <x-slot:breadcrumb>
                <a href="{{ route('home') }}" class="link-muted">Venues</a>
                <span class="breadcrumb-sep">/</span>
                <span>{{ $reservation->reference }}</span>
            </x-slot:breadcrumb>
        </x-ui.page-header>

        @if ($reservation->isPending())
            <x-ui.alert variant="warning" title="Waiting for the venue to confirm" style="margin-bottom: var(--space-5);">
                {{ $reservation->business->name }} has until
                <strong>{{ $reservation->response_deadline->format('D j M, g:i A') }}</strong> to respond.
                We'll notify you either way — if they don't reply by then, the request expires
                and the slot is released.
            </x-ui.alert>
        @elseif ($reservation->isConfirmed())
            <x-ui.alert variant="success" title="Confirmed" style="margin-bottom: var(--space-5);">
                Your spot is reserved. Quote your reference at the venue and pay when you arrive.
            </x-ui.alert>
        @elseif ($reservation->status === \App\Enums\ReservationStatus::Rejected)
            <x-ui.alert variant="danger" title="This request was declined" style="margin-bottom: var(--space-5);">
                {{ $reservation->rejection_reason_code?->userMessage() }}
                @if ($reservation->rejection_reason_text)
                    <br>{{ $reservation->rejection_reason_text }}
                @endif
            </x-ui.alert>
        @elseif ($reservation->status === \App\Enums\ReservationStatus::Expired)
            <x-ui.alert variant="neutral" title="This request expired" style="margin-bottom: var(--space-5);">
                The venue didn't respond in time, so the slot was released.
                <a href="{{ route('businesses.show', $reservation->business) }}" class="link">Try another time</a>.
            </x-ui.alert>
        @endif

        {{-- The booking as a ticket stub. Leads the page: it is what the
             customer came back to this screen to look at. --}}
        <x-ui.stub :reservation="$reservation" data-reveal style="margin-bottom: var(--space-6);" />

        <x-ui.card>
            <div class="stack-6">
                <dl class="detail-list">
                    <dt>Status</dt>
                    <dd>
                        <x-ui.badge :status="$reservation->status->value">
                            {{ $reservation->status->label() }}
                        </x-ui.badge>
                    </dd>

                    <dt>Venue</dt>
                    <dd>
                        <a href="{{ route('businesses.show', $reservation->business) }}" class="link">
                            {{ $reservation->business->name }}
                        </a>
                        <div class="text-sm text-muted">
                            {{ $reservation->business->address }}, {{ $reservation->business->area }}
                        </div>
                    </dd>

                    <dt>Spot</dt>
                    <dd>{{ $reservation->spot->name }} ({{ $reservation->spot->businessGame->game->name }})</dd>

                    <dt>When</dt>
                    <dd>
                        {{ $reservation->dateLabel() }}<br>
                        <span class="text-muted">{{ $reservation->timeRangeLabel() }} · {{ $reservation->durationLabel() }}</span>
                    </dd>

                    <dt>Price</dt>
                    <dd>
                        <strong>{{ $reservation->totalPriceLabel() }}</strong>
                        <div class="text-sm text-muted">
                            {{-- The snapshot, not the spot's live rate: a later price
                                 change must not rewrite what was agreed (SRS 9.10). --}}
                            {{ \App\Support\Money::pkr($reservation->price_amount_snapshot) }}
                            per {{ \App\Support\Money::duration($reservation->price_unit_minutes_snapshot) }}
                            · paid at the venue
                        </div>
                    </dd>

                    <dt>Contact</dt>
                    <dd>{{ $reservation->business->contact_number }}</dd>

                    @if ($reservation->customer_note)
                        <dt>Your note</dt>
                        <dd>{{ $reservation->customer_note }}</dd>
                    @endif
                </dl>
            </div>

            <x-slot:footer>
                <div class="split">
                    <a href="{{ route('businesses.show', $reservation->business) }}" class="link">
                        Back to {{ $reservation->business->name }}
                    </a>

                    {{-- Cancellation lands with the booking history screen. --}}
                    @if (Route::has('bookings.index'))
                        <x-ui.button :href="route('bookings.index')" variant="secondary" size="sm">
                            All my bookings
                        </x-ui.button>
                    @endif
                </div>
            </x-slot:footer>
        </x-ui.card>
    </div>
</x-app-layout>
