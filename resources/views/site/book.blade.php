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
            <x-ui.card title="Choose a date">
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
            </x-ui.card>

            <x-ui.card
                title="How long?"
                subtitle="This spot is booked in {{ \App\Support\Money::duration($spot->price_unit_minutes) }} blocks."
            >
                {{-- Only durations the spot actually sells are offered, so
                     SRS 9.7 can't be violated from the UI at all. --}}
                <x-ui.field label="Booking length" name="duration_minutes">
                    <x-ui.select name="duration_minutes" data-duration>
                        @foreach ($durations as $option)
                            <option value="{{ $option }}" @selected($option === $duration)>
                                {{ \App\Support\Money::duration($option) }}
                            </option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            </x-ui.card>

            <x-ui.card title="Pick a start time">
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
                        <div class="booking-total-note">Paid at the venue</div>
                    </div>
                    <div class="booking-total-value" data-total>{{ \App\Support\Money::pkr($total) }}</div>
                </div>

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
                            <x-ui.button type="submit" variant="primary" block data-submit>
                                Send request
                            </x-ui.button>

                            <p class="text-sm text-muted" style="margin-top: var(--space-3);">
                                Nothing is charged now. The venue confirms your request, then you pay when you arrive.
                            </p>
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
