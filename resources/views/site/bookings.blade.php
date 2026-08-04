<x-app-layout title="My bookings">
    <x-ui.page-header
        title="My bookings"
        description="Everything you've requested, upcoming and past."
    >
        <x-slot:actions>
            <x-ui.button :href="route('home')" variant="secondary" icon="search">Find a spot</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="stack-8">
        <div class="stack-3">
            <h2 class="h3">Upcoming</h2>

            @if ($upcoming->isEmpty())
                <x-ui.card>
                    <x-ui.empty-state icon="calendar" title="Nothing coming up">
                        Find a venue and send a request — you'll see it here while the venue confirms.

                        <x-slot:action>
                            <x-ui.button :href="route('home')" variant="primary">Browse venues</x-ui.button>
                        </x-slot:action>
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                @foreach ($upcoming as $booking)
                    <x-ui.card>
                        <div class="split split-start">
                            <div class="stack-2 flex-1">
                                <div class="cluster-2">
                                    <a href="{{ route('bookings.show', $booking) }}" class="h4 link">
                                        {{ $booking->spot->name }}
                                    </a>
                                    <x-ui.badge :status="$booking->status->value">
                                        {{ $booking->status->label() }}
                                    </x-ui.badge>
                                </div>

                                <div class="text-muted">
                                    {{ $booking->business->name }} · {{ $booking->business->area }}
                                </div>

                                <div>
                                    <strong>{{ $booking->dateLabel() }}</strong>,
                                    {{ $booking->timeRangeLabel() }}
                                    <span class="text-muted">({{ $booking->durationLabel() }})</span>
                                </div>

                                @if ($booking->isPending())
                                    <div class="text-sm text-warning">
                                        Waiting for the venue — expires
                                        {{ $booking->response_deadline->diffForHumans() }} if they don't reply.
                                    </div>
                                @else
                                    <div class="text-sm">
                                        Quote <span class="mono font-semibold">{{ $booking->reference }}</span> at the venue.
                                    </div>
                                @endif
                            </div>

                            <div class="text-right stack-2">
                                <div class="h4">{{ $booking->totalPriceLabel() }}</div>

                                <div class="text-xs text-muted">Pay at the venue</div>

                                {{-- FR-4.9: always available while the booking is live.
                                     A late cancellation is allowed but flagged -- there
                                     is no payment to forfeit in V1. --}}
                                <x-ui.button
                                    variant="ghost" size="sm" type="button"
                                    data-modal-open="cancel-{{ $booking->id }}"
                                >Cancel</x-ui.button>
                            </div>
                        </div>
                    </x-ui.card>
                @endforeach
            @endif
        </div>

        <div class="stack-3">
            <h2 class="h3">Past</h2>

            @if ($past->isEmpty())
                <x-ui.card>
                    <x-ui.empty-state icon="clock" title="No history yet">
                        Bookings move here once they're finished, cancelled or declined.
                    </x-ui.empty-state>
                </x-ui.card>
            @else
                <x-ui.card flush>
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Reference</th>
                                    <th>Venue</th>
                                    <th>Spot</th>
                                    <th>When</th>
                                    <th class="cell-numeric">Price</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($past as $booking)
                                    <tr>
                                        <td>
                                            <a href="{{ route('bookings.show', $booking) }}" class="link mono">
                                                {{ $booking->reference }}
                                            </a>
                                        </td>
                                        <td>{{ $booking->business->name }}</td>
                                        <td>
                                            {{ $booking->spot->name }}
                                            <div class="cell-secondary">{{ $booking->spot->businessGame->game->name }}</div>
                                        </td>
                                        <td>
                                            <div class="cell-primary">{{ $booking->dateLabel() }}</div>
                                            <div class="cell-secondary">{{ $booking->timeRangeLabel() }}</div>
                                        </td>
                                        <td class="cell-numeric">{{ $booking->totalPriceLabel() }}</td>

                                        <td>
                                            <x-ui.badge :status="$booking->status->value">
                                                {{ $booking->status->label() }}
                                            </x-ui.badge>
                                            @if ($booking->is_late_cancellation)
                                                <div class="cell-secondary text-warning">Late cancellation</div>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div style="padding: 0 var(--space-5) var(--space-4);">{{ $past->links() }}</div>
                </x-ui.card>
            @endif
        </div>
    </div>

    @foreach ($upcoming as $booking)
        <x-ui.modal
            id="cancel-{{ $booking->id }}"
            title="Cancel this booking?"
            subtitle="{{ $booking->spot->name }} at {{ $booking->business->name }}, {{ $booking->dateLabel() }}"
            width="narrow"
        >
            <form method="POST" action="{{ route('bookings.cancel', $booking) }}" class="form" id="cancel-form-{{ $booking->id }}">
                @csrf

                @if ($booking->isWithinCancellationCutoff())
                    {{-- SRS 9.3: cancelling late is permitted, but the venue is told.
                         Saying so up front is fairer than flagging it silently. --}}
                    <x-ui.alert variant="warning">
                        This booking starts soon, so the venue will be told it was a late cancellation.
                        Repeated late cancellations make venues less likely to accept your requests.
                    </x-ui.alert>
                @endif

                <x-ui.field label="Reason" name="reason" hint="Optional — helps the venue plan.">
                    <x-ui.textarea name="reason" rows="2" placeholder="Plans changed." />
                </x-ui.field>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" type="button" data-modal-close>Keep it</x-ui.button>
                <x-ui.button variant="danger" type="submit" form="cancel-form-{{ $booking->id }}">
                    Cancel booking
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endforeach
</x-app-layout>
