<x-app-layout :title="$business->name">
    <div class="breadcrumb" style="margin-bottom: var(--space-3);">
        <a href="{{ route('home') }}" class="link-muted">Venues</a>
        <span class="breadcrumb-sep">/</span>
        <span>{{ $business->name }}</span>
    </div>

    <div class="venue-gallery">
        @forelse ($business->images as $image)
            @break($loop->index >= 5)
            <img src="{{ $image->url() }}" alt="{{ $business->name }}" loading="lazy">
        @empty
            <div class="venue-gallery-empty">
                <x-ui.icon name="image" :size="28" />
                <span class="text-sm">No photos yet</span>
            </div>
        @endforelse
    </div>

    <div class="layout-with-aside">
        <div class="stack-6">
            <div class="stack-2">
                <h1 class="h1">{{ $business->name }}</h1>

                <div class="cluster-4 text-muted">
                    <span class="cluster-1"><x-ui.icon name="map-pin" :size="14" /> {{ $business->address }}, {{ $business->area }}</span>
                    <span class="cluster-1"><x-ui.icon name="phone" :size="14" /> {{ $business->contact_number }}</span>
                </div>

                <div class="cluster-1" style="flex-wrap: wrap;">
                    @foreach ($business->businessGames as $businessGame)
                        <span class="tag">{{ $businessGame->game->name }}</span>
                    @endforeach
                </div>
            </div>

            @if ($business->description)
                <div class="prose"><p>{{ $business->description }}</p></div>
            @endif

            {{-- Date strip: pick a day and the availability below updates. --}}
            <div class="stack-2">
                <h2 class="h4">Availability</h2>
                <div class="date-strip">
                    @foreach ($dateOptions as $option)
                        <a href="{{ route('businesses.show', ['business' => $business, 'date' => $option->toDateString()]) }}"
                           class="date-chip {{ $option->isSameDay($date) ? 'is-active' : '' }}">
                            <span class="date-chip-day">{{ $option->isToday() ? 'Today' : $option->format('D') }}</span>
                            <span class="date-chip-date">{{ $option->format('j') }}</span>
                            <span class="date-chip-day">{{ $option->format('M') }}</span>
                        </a>
                    @endforeach
                </div>
            </div>

            @foreach ($business->businessGames as $businessGame)
                @continue($businessGame->spots->isEmpty())

                <div class="stack-3">
                    <h2 class="h4">{{ $businessGame->game->name }}</h2>

                    @foreach ($businessGame->spots as $spot)
                        @php
                            $windows = $availability[$spot->id] ?? [];
                        @endphp

                        <div class="spot-row {{ $windows === [] ? 'is-full' : '' }}">
                            <div>
                                <div class="spot-row-name">{{ $spot->name }}</div>

                                <div class="spot-row-meta">
                                    {{ \App\Support\Money::duration($spot->min_duration_minutes) }}
                                    – {{ \App\Support\Money::duration($spot->max_duration_minutes) }}
                                    · booked in {{ \App\Support\Money::duration($spot->price_unit_minutes) }} blocks
                                    @if ($spot->hasHoursOverride())
                                        · <span class="text-warning">different hours: {{ $spot->effectiveHours()->summary() }}</span>
                                    @endif
                                </div>

                                @if ($spot->description)
                                    <div class="spot-row-meta">{{ $spot->description }}</div>
                                @endif

                                <div class="availability">
                                    @forelse ($windows as $window)
                                        <span class="availability-window">
                                            {{ $window['start']->format('g:i A') }} – {{ $window['end']->format('g:i A') }}
                                        </span>
                                    @empty
                                        <span class="availability-none">
                                            Fully booked on {{ $date->format('D j M') }} — try another day.
                                        </span>
                                    @endforelse
                                </div>
                            </div>

                            <div class="text-right stack-2">
                                <div class="spot-row-price">{{ $spot->rateLabel() }}</div>

                                @if ($windows !== [])
                                    <x-ui.button
                                        :href="route('bookings.create', ['spot' => $spot, 'date' => $date->toDateString()])"
                                        variant="primary"
                                        size="sm"
                                    >Book</x-ui.button>
                                @else
                                    <x-ui.button variant="secondary" size="sm" disabled>Unavailable</x-ui.button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>

        <aside class="aside-sticky">
            <x-ui.card title="Opening hours">
                <dl class="detail-list" style="grid-template-columns: 90px 1fr;">
                    @foreach (\App\Support\OperatingHours::DAYS as $day)
                        @php
                            $ranges = $business->hours()->forDay($day);
                        @endphp
                        <dt>{{ \App\Support\OperatingHours::DAY_LABELS[$day] }}</dt>
                        <dd>
                            @if ($ranges === [])
                                <span class="text-muted">Closed</span>
                            @else
                                @foreach ($ranges as $range)
                                    {{ \App\Support\OperatingHours::displayTime($range['open']) }}
                                    – {{ \App\Support\OperatingHours::displayTime($range['close']) }}
                                    @if (! $loop->last)<br>@endif
                                @endforeach
                            @endif
                        </dd>
                    @endforeach
                </dl>
            </x-ui.card>

            <x-ui.card title="How booking works" style="margin-top: var(--space-4);">
                <div class="stack-3 text-sm">
                    <div class="cluster-2 cluster-start">
                        <span class="tag">1</span>
                        <span>Pick a spot and a time, then send your request.</span>
                    </div>
                    <div class="cluster-2 cluster-start">
                        <span class="tag">2</span>
                        <span>The venue confirms — you'll be notified either way.</span>
                    </div>
                    <div class="cluster-2 cluster-start">
                        <span class="tag">3</span>
                        <span>Quote your booking reference and pay at the venue.</span>
                    </div>
                </div>
            </x-ui.card>
        </aside>
    </div>
</x-app-layout>
