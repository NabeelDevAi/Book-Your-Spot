<x-console-layout title="New booking" context="owner">
    <x-ui.page-header
        :title="'New booking — '.$spot->name"
        description="Record a walk-in or phone booking. It's confirmed immediately -- there's no one else who needs to approve it."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('owner.businesses.index') }}" class="link-muted">Venues</a>
            <span class="breadcrumb-sep">/</span>
            <a href="{{ route('owner.businesses.spots.index', $business) }}" class="link-muted">{{ $business->name }}</a>
            <span class="breadcrumb-sep">/</span>
            <span>New booking</span>
        </x-slot:breadcrumb>
    </x-ui.page-header>

    <form
        method="POST"
        action="{{ route('owner.businesses.spots.reservations.store', [$business, $spot]) }}"
        class="layout-with-aside"
        data-booking-form
        data-slots-url="{{ route('owner.businesses.spots.reservations.slots', [$business, $spot]) }}"
    >
        @csrf

        <div class="stack-6">
            <x-ui.card title="Who's this for?">
                <div class="form">
                    <fieldset>
                        <legend class="field-label" style="margin-bottom: var(--space-2);">How was this booked?</legend>
                        <div class="stack-2">
                            @foreach ($channels as $channel)
                                <label class="choice-card">
                                    <input type="radio" name="channel" value="{{ $channel->value }}" @checked(old('channel', $channels[0]->value) === $channel->value)>
                                    <span><span class="choice-card-title">{{ $channel->label() }}</span></span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <div class="form-row">
                        <x-ui.field label="Customer name" name="customer_name" required>
                            <x-ui.input name="customer_name" :value="old('customer_name')" required placeholder="Ali Raza" />
                        </x-ui.field>

                        <x-ui.field
                            label="Phone number"
                            name="customer_phone"
                            required
                            hint="If this matches an existing account, the booking is linked to it."
                        >
                            <x-ui.input name="customer_phone" :value="old('customer_phone')" required placeholder="03xx xxxxxxx" />
                        </x-ui.field>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card>
                <x-slot:title>
                    <span class="step"><span class="step-number">1</span> Choose a date</span>
                </x-slot:title>

                <div class="date-strip">
                    @foreach ($dateOptions as $option)
                        <label class="date-chip {{ $option->isSameDay($date) ? 'is-active' : '' }}">
                            <input
                                type="radio"
                                name="date"
                                value="{{ $option->toDateString() }}"
                                @checked($option->isSameDay($date))
                                data-date
                                style="position:absolute;opacity:0;pointer-events:none;"
                            >
                            <span class="date-chip-day">{{ $option->isToday() ? 'Today' : $option->format('D') }}</span>
                            <span class="date-chip-date">{{ $option->format('j') }}</span>
                            <span class="date-chip-day">{{ $option->format('M') }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="board board-lines booking-board" style="margin-top: var(--space-5);">
                    <x-ui.ribbon :day="$date" :open="$freeWindows" density="tall" scale />

                    <div class="ribbon-legend" style="margin-top: var(--space-3);">
                        <span><span class="ribbon-legend-swatch"></span> Free</span>
                        <span><span class="ribbon-legend-swatch is-busy"></span> Taken or closed</span>
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card
                subtitle="This spot is booked in {{ \App\Support\Money::duration($spot->price_unit_minutes) }} blocks."
            >
                <x-slot:title>
                    <span class="step"><span class="step-number">2</span> How long?</span>
                </x-slot:title>

                <input type="hidden" name="duration_minutes" id="duration-input"
                       value="{{ $duration }}" data-duration>

                <div class="segmented" data-segmented="#duration-input"
                     role="radiogroup" aria-label="Booking length">
                    @foreach ($durations as $option)
                        <button type="button"
                                class="segmented-option {{ $option === $duration ? 'is-active' : '' }}"
                                data-segmented-option="{{ $option }}"
                                role="radio"
                                aria-checked="{{ $option === $duration ? 'true' : 'false' }}"
                                tabindex="{{ $option === $duration ? '0' : '-1' }}">
                            {{ \App\Support\Money::duration($option) }}
                        </button>
                    @endforeach
                </div>
            </x-ui.card>

            <x-ui.card>
                <x-slot:title>
                    <span class="step"><span class="step-number">3</span> Pick a start time</span>
                </x-slot:title>

                @error('start_datetime')
                    <x-ui.alert variant="danger" style="margin-bottom: var(--space-4);">{{ $message }}</x-ui.alert>
                @enderror
                @error('duration_minutes')
                    <x-ui.alert variant="danger" style="margin-bottom: var(--space-4);">{{ $message }}</x-ui.alert>
                @enderror

                <div class="slot-grid" data-slots>
                    @forelse ($startTimes as $start)
                        <label class="slot">
                            <input
                                type="radio"
                                name="start_datetime"
                                value="{{ $start->format('Y-m-d H:i') }}"
                                @checked(old('start_datetime') === $start->format('Y-m-d H:i'))
                            >
                            <span>{{ $start->format('g:i A') }}</span>
                            <span class="slot-end">to {{ $start->copy()->addMinutes($duration)->format('g:i A') }}</span>
                        </label>
                    @empty
                        <p class="availability-none">
                            No free times of this length on {{ $date->format('D j M') }}.
                            Try a shorter booking or another day.
                        </p>
                    @endforelse
                </div>
            </x-ui.card>

            <x-ui.card title="Anything to note?">
                <x-ui.field label="Note" name="note" hint="Optional — e.g. number of players, equipment needed.">
                    <x-ui.textarea name="note" rows="2" placeholder="Four players, walk-in." />
                </x-ui.field>
            </x-ui.card>
        </div>

        <aside class="aside-sticky">
            <x-ui.card title="Booking summary">
                <dl class="booking-summary">
                    <div class="booking-summary-row">
                        <dt>Venue</dt>
                        <dd>{{ $business->name }}</dd>
                    </div>
                    <div class="booking-summary-row">
                        <dt>Spot</dt>
                        <dd>{{ $spot->name }}</dd>
                    </div>
                    <div class="booking-summary-row">
                        <dt>Rate</dt>
                        <dd>{{ $spot->rateLabel() }}</dd>
                    </div>
                    <div class="booking-summary-row">
                        <dt>Breakdown</dt>
                        <dd data-price-explanation>{{ $priceExplanation }}</dd>
                    </div>
                </dl>

                <div class="booking-total">
                    <div>
                        <div class="text-sm text-muted">Total</div>
                        <div class="booking-total-note">Collected at the venue</div>
                    </div>
                    <div class="booking-total-value" data-total>{{ \App\Support\Money::pkr($total) }}</div>
                </div>

                <div style="margin-top: var(--space-5);">
                    <x-ui.button type="submit" variant="primary" block data-submit>
                        Confirm booking
                    </x-ui.button>

                    <p class="text-sm text-muted" style="margin-top: var(--space-3);">
                        This slot is reserved immediately -- no approval step, since you're recording it yourself.
                    </p>
                </div>
            </x-ui.card>

            <x-ui.card title="Opening hours" style="margin-top: var(--space-4);">
                <p class="text-sm text-muted">{{ $spot->effectiveHours()->summary() }}</p>
            </x-ui.card>
        </aside>
    </form>
</x-console-layout>
