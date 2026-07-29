<x-app-layout title="Book {{ $spot->name }}">
    <div class="breadcrumb" style="margin-bottom: var(--space-3);">
        <a href="{{ route('home') }}" class="link-muted">Venues</a>
        <span class="breadcrumb-sep">/</span>
        <a href="{{ route('businesses.show', $spot->business) }}" class="link-muted">{{ $spot->business->name }}</a>
        <span class="breadcrumb-sep">/</span>
        <span>{{ $spot->name }}</span>
    </div>

    <x-ui.page-header
        :title="$spot->name"
        :description="$spot->businessGame->game->name.' at '.$spot->business->name"
    />

    @guest
        {{-- SRS 9.13: guests see the whole form with real prices and real
             availability. Only the submit button asks them to sign in --
             hiding this would defeat the pricing transparency the platform
             exists to provide. --}}
        <x-ui.alert variant="info" style="margin-bottom: var(--space-5);">
            You can browse times and prices freely. You'll be asked to sign in when you send the request.
        </x-ui.alert>
    @endguest

    @auth
        @if (! auth()->user()->isUser())
            <x-ui.alert variant="warning" title="Owner accounts can't book" style="margin-bottom: var(--space-5);">
                Use <strong>Blocked times</strong> in your console to reserve your own spot, or register a
                separate customer account to book elsewhere.
            </x-ui.alert>
        @endif
    @endauth

    <form
        method="POST"
        action="{{ route('bookings.store', $spot) }}"
        class="layout-with-aside"
        data-booking-form
        data-slots-url="{{ route('bookings.slots', $spot) }}"
    >
        @csrf

        <div class="stack-6">
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

                {{-- The chosen day as a board: what is already gone, and what
                     is left. Everything below is choosing within this. --}}
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

                {{-- Only durations the spot actually sells are offered, so
                     SRS 9.7 can't be violated from the UI at all.

                     A hidden input behind a segmented control: the form field
                     is unchanged, so validation, old() and the slot refetch in
                     booking-form.js all work exactly as they did with a
                     <select>. See public/assets/js/segmented.js. --}}
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

            <x-ui.card title="Anything the venue should know?">
                <x-ui.field label="Note" name="note" hint="Optional — e.g. number of players, equipment needed.">
                    <x-ui.textarea name="note" rows="2" placeholder="Four players, we'll bring our own cues." />
                </x-ui.field>
            </x-ui.card>
        </div>

        <aside class="aside-sticky">
            <x-ui.card title="Your booking">
                <dl class="booking-summary">
                    <div class="booking-summary-row">
                        <dt>Venue</dt>
                        <dd>{{ $spot->business->name }}</dd>
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
                        <div class="booking-total-note">Held from your wallet now</div>
                    </div>
                    <div class="booking-total-value" data-total>{{ \App\Support\Money::pkr($total) }}</div>
                </div>

                {{-- The refund terms are shown BEFORE paying, not discovered at
                     the moment of cancelling. These are the same tiers that get
                     snapshotted onto the reservation on submit. --}}
                <details class="booking-policy">
                    <summary>Cancellation policy</summary>
                    <ul>
                        @foreach (app(\App\Services\Booking\RefundResolver::class)->describe() as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                        <li>If the venue cancels, or doesn't reply in time, you get a full refund.</li>
                    </ul>
                </details>

                <div style="margin-top: var(--space-5);">
                    @guest
                        {{-- The intended URL is preserved, so signing in returns
                             the customer to this exact form (SRS 9.13). --}}
                        <x-ui.button
                            :href="route('login', ['redirect' => url()->full()])"
                            variant="primary"
                            block
                        >Sign in to request</x-ui.button>

                        <p class="text-center text-sm text-muted" style="margin-top: var(--space-3);">
                            No account? <a href="{{ route('register') }}" class="link">Create one</a>
                        </p>
                    @else
                        @if (auth()->user()->isUser())
                            @php($availableMinor = auth()->user()->walletAvailableMinor())
                            @php($shortfallMinor = \App\Support\Money::toMinor((string) $total) - $availableMinor)

                            {{-- Tell them before they submit, not after. The
                                 server refuses either way, but a form that
                                 rejects you on submit for a reason it already
                                 knew is just rude. --}}
                            @if ($shortfallMinor > 0)
                                <x-ui.alert variant="warning" :icon="false" style="margin-bottom: var(--space-3);">
                                    You have {{ \App\Support\Money::pkrMinor($availableMinor) }} available.
                                    Top up {{ \App\Support\Money::pkrMinor($shortfallMinor) }} to book this slot.
                                </x-ui.alert>

                                <x-ui.button :href="route('wallet.show')" variant="primary" block icon="wallet">
                                    Top up wallet
                                </x-ui.button>
                            @else
                                <x-ui.button type="submit" variant="primary" block data-submit>
                                    Send request
                                </x-ui.button>

                                <p class="text-sm text-muted" style="margin-top: var(--space-3);">
                                    We hold {{ \App\Support\Money::pkr($total) }} from your wallet while the venue
                                    replies. If they decline or don't answer, it's released straight back.
                                </p>
                            @endif
                        @else
                            <x-ui.button variant="secondary" block disabled>Owner accounts can't book</x-ui.button>
                        @endif
                    @endguest
                </div>
            </x-ui.card>

            <x-ui.card title="Opening hours" style="margin-top: var(--space-4);">
                <p class="text-sm text-muted">{{ $spot->effectiveHours()->summary() }}</p>

                @if ($freeWindows !== [])
                    <p class="text-sm" style="margin-top: var(--space-3);">Free on {{ $date->format('D j M') }}:</p>
                    <div class="availability">
                        @foreach ($freeWindows as $window)
                            <span class="availability-window">
                                {{ $window['start']->format('g:i A') }} – {{ $window['end']->format('g:i A') }}
                            </span>
                        @endforeach
                    </div>
                @endif
            </x-ui.card>
        </aside>
    </form>
</x-app-layout>
