<x-console-layout :title="$reservation->reference" context="owner">
    <x-ui.page-header :title="'Booking '.$reservation->reference">
        <x-slot:breadcrumb>
            <a href="{{ route('owner.reservations.index') }}" class="link-muted">Bookings</a>
            <span class="breadcrumb-sep">/</span>
            <span>{{ $reservation->reference }}</span>
        </x-slot:breadcrumb>

        <x-slot:actions>
            @if ($reservation->isManual())
                <x-ui.badge variant="neutral">{{ $reservation->channel->label() }}</x-ui.badge>
            @endif
            <x-ui.badge :status="$reservation->status->value">{{ $reservation->status->label() }}</x-ui.badge>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($reservation->hasOpenConflict())
        <x-ui.alert variant="danger" title="This booking clashes with something you scheduled" style="margin-bottom: var(--space-5);">
            You blocked this spot or took it out of service while this booking was live.
            It has <strong>not</strong> been cancelled — contact the customer and record the outcome
            in <a href="{{ route('owner.conflicts.index') }}" class="link">Clashes</a>.
        </x-ui.alert>
    @endif

    @if ($customerNoShows > 0)
        {{-- SRS 9.12: without payments this record is the only deterrent, so it
             appears at the exact moment the owner decides. --}}
        <x-ui.alert
            :variant="$reservation->user?->isRepeatNoShow() ? 'danger' : 'warning'"
            title="This customer has {{ $customerNoShows }} recorded no-show{{ $customerNoShows === 1 ? '' : 's' }}"
            style="margin-bottom: var(--space-5);"
        >
            @if ($reservation->user?->isRepeatNoShow())
                They have failed to turn up more than once. Consider calling to confirm before approving.
            @else
                They have missed a booking before.
            @endif
        </x-ui.alert>
    @endif

    <div class="layout-with-aside">
        <div class="stack-6">
            <x-ui.card title="Booking details">
                <dl class="detail-list">
                    <dt>Venue</dt><dd>{{ $reservation->business->name }}</dd>
                    <dt>Spot</dt>
                    <dd>{{ $reservation->spot->name }} ({{ $reservation->spot->businessGame->game->name }})</dd>
                    <dt>Date</dt><dd>{{ $reservation->dateLabel() }}</dd>
                    <dt>Time</dt>
                    <dd>{{ $reservation->timeRangeLabel() }} · {{ $reservation->durationLabel() }}</dd>
                    <dt>Price</dt>
                    <dd>
                        <strong>{{ $reservation->totalPriceLabel() }}</strong>
                        <div class="text-sm text-muted">
                            {{-- The snapshot, not today's rate: a price change must
                                 not rewrite what was agreed (SRS 9.10). --}}
                            {{ \App\Support\Money::pkr($reservation->price_amount_snapshot) }}
                            per {{ \App\Support\Money::duration($reservation->price_unit_minutes_snapshot) }}
                            at time of booking · collected at the venue
                        </div>
                    </dd>
                    <dt>Requested</dt><dd>{{ $reservation->requested_at?->format('j M Y, g:i A') }}</dd>

                    @if ($reservation->isPending())
                        <dt>Respond by</dt>
                        <dd>
                            {{ $reservation->response_deadline->format('D j M, g:i A') }}
                            <span class="text-muted">({{ $reservation->response_deadline->diffForHumans() }})</span>
                        </dd>
                    @endif

                    @if ($reservation->customer_note)
                        <dt>Customer note</dt><dd>{{ $reservation->customer_note }}</dd>
                    @endif

                    @if ($reservation->rejection_reason_code)
                        <dt>Declined because</dt>
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

            <x-ui.card title="Customer">
                <dl class="detail-list">
                    <dt>Name</dt><dd>{{ $reservation->customerDisplayName() }}</dd>
                    <dt>Phone</dt><dd>{{ $reservation->customerDisplayPhone() ?? '—' }}</dd>
                    @if ($reservation->user)
                        <dt>Email</dt><dd>{{ $reservation->user->email }}</dd>
                        <dt>No-shows</dt>
                        <dd>
                            @if ($customerNoShows > 0)
                                <x-ui.badge :variant="$reservation->user->isRepeatNoShow() ? 'danger' : 'warning'">
                                    {{ $customerNoShows }}
                                </x-ui.badge>
                            @else
                                None on record
                            @endif
                        </dd>
                    @else
                        <dt>Account</dt>
                        <dd>No platform account — recorded by staff as a {{ strtolower($reservation->channel->label()) }} booking.</dd>
                    @endif
                </dl>
            </x-ui.card>
        </div>

        <aside class="aside-sticky">
            <x-ui.card title="Your decision">
                @if ($reservation->isPending())
                    <div class="stack-3">
                        <form method="POST" action="{{ route('owner.reservations.approve', $reservation) }}">
                            @csrf
                            <x-ui.button type="submit" variant="success" block icon="check">
                                Approve booking
                            </x-ui.button>
                        </form>

                        <x-ui.button variant="danger-outline" block type="button" data-modal-open="reject-booking">
                            Decline
                        </x-ui.button>

                        <p class="text-xs text-muted">
                            Approving confirms this slot. Any other requests for the same time are
                            declined automatically and those customers are told the slot was taken.
                        </p>
                    </div>
                @elseif ($reservation->isConfirmed())
                    <div class="stack-3">
                        @if ($reservation->hasEnded())
                            {{-- FR-2.8: only possible once the booking has actually
                                 passed, so nobody is marked unfairly. --}}
                            <form method="POST" action="{{ route('owner.reservations.no-show', $reservation) }}"
                                  data-confirm="Record a no-show?"
                                  data-confirm-detail="This is added to the customer's record and shown to venues reviewing their future requests."
                                  data-confirm-action="Record no-show">
                                @csrf
                                <x-ui.button type="submit" variant="danger-outline" block icon="flag">
                                    Customer didn't turn up
                                </x-ui.button>
                            </form>
                        @else
                            <p class="text-sm text-muted">
                                Confirmed. You can record a no-show after the booking has ended.
                            </p>
                        @endif

                        <x-ui.button variant="ghost" block type="button" data-modal-open="cancel-booking">
                            Cancel this booking
                        </x-ui.button>
                    </div>
                @else
                    <p class="text-sm text-muted">
                        This booking is {{ strtolower($reservation->status->label()) }} — no further action is possible.
                    </p>
                @endif
            </x-ui.card>
        </aside>
    </div>

    {{-- SRS 9.19: one-click reasons so owners aren't forced to write an essay,
         with optional free text so the customer still gets context. --}}
    <x-ui.modal id="reject-booking" title="Decline this request" subtitle="{{ $reservation->customerDisplayName() }} will be notified with the reason you pick.">
        <form method="POST" action="{{ route('owner.reservations.reject', $reservation) }}" class="form" id="reject-form">
            @csrf

            <fieldset>
                <legend class="field-label" style="margin-bottom: var(--space-2);">Reason</legend>
                <div class="stack-2">
                    @foreach ($rejectionReasons as $reason)
                        <label class="choice-card">
                            <input type="radio" name="reason_code" value="{{ $reason->value }}" @checked($loop->first)>
                            <span><span class="choice-card-title">{{ $reason->label() }}</span></span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <x-ui.field label="Anything to add?" name="reason_text" hint="Optional — shown to the customer.">
                <x-ui.textarea name="reason_text" rows="2" placeholder="Private tournament that evening." />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" type="button" data-modal-close>Back</x-ui.button>
            <x-ui.button variant="danger" type="submit" form="reject-form">Decline request</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    {{-- SRS 9.4: owner-side cancellations reflect on venue reliability, so a
         reason is mandatory rather than optional. --}}
    <x-ui.modal id="cancel-booking" title="Cancel this booking" subtitle="The customer will be notified immediately.">
        <form method="POST" action="{{ route('owner.reservations.cancel', $reservation) }}" class="form" id="owner-cancel-form">
            @csrf

            <x-ui.alert variant="warning">
                Cancelling a confirmed booking is recorded against your venue. Only do this if you
                genuinely can't honour it.
            </x-ui.alert>

            <x-ui.field label="Reason" name="reason" required hint="Shown to the customer.">
                <x-ui.textarea name="reason" rows="3" required placeholder="Equipment failure — table out of action." />
            </x-ui.field>
        </form>

        <x-slot:footer>
            <x-ui.button variant="secondary" type="button" data-modal-close>Keep booking</x-ui.button>
            <x-ui.button variant="danger" type="submit" form="owner-cancel-form">Cancel booking</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</x-console-layout>
