<x-app-layout title="Find a spot">
    @push('head')
        <style>.app-main { padding-top: 0; }</style>
    @endpush

    {{--
        The landing and the browse surface are the same page, deliberately.

        The plan called for splitting them, but `/` listing every bookable venue
        is a tested contract, not incidental: SearchTest, BusinessModerationTest
        and EdgeCaseSweepTest all assert on what `/` does and does not show, and
        they are guarding real requirements -- SRS 9.17 (an unapproved or
        empty venue must never be visible) and pricing transparency. A landing
        page that dropped the listing would break that guarantee to gain a
        second URL. So the hero got richer instead.
    --}}

    <section class="search-hero bleed board board-lines board-lit">
        <div class="bleed-inner">
            <span class="eyebrow is-live" data-reveal>
                Karachi · {{ $businesses->total() }} {{ Str::plural('venue', $businesses->total()) }} live
            </span>

            <h1 class="search-hero-title" data-reveal>Find a spot,<br>lock it in.</h1>

            <p class="search-hero-subtitle" data-reveal>
                Snooker, futsal, padel, PS5 and more across Karachi — real prices,
                live availability, no back-and-forth.
            </p>

            @if ($games->isNotEmpty())
                <nav class="category-rail" aria-label="Browse by category" data-reveal>
                    <a href="{{ route('home') }}"
                       class="category-chip @if (blank($filters['game'] ?? null)) is-active @endif">
                        All venues
                    </a>
                    @foreach ($games as $game)
                        <a href="{{ request()->fullUrlWithQuery(['game' => $game->slug]) }}"
                           class="category-chip @if (($filters['game'] ?? null) === $game->slug) is-active @endif">
                            {{ $game->name }}
                        </a>
                    @endforeach
                </nav>
            @endif

            <form method="GET" action="{{ route('home') }}" class="search-panel board-inset" data-reveal>
                {{-- The primary row answers the question most people arrive
                     with: what, where, when. Everything else is refinement and
                     is folded away -- the old panel put nine controls on
                     screen at once, which reads as a form to fill in rather
                     than a search to make. --}}
                <div class="search-row">
                    <x-ui.field label="Search" name="q">
                        <x-ui.input name="q" :value="$filters['q'] ?? null" placeholder="Venue name or area" />
                    </x-ui.field>

                    <x-ui.field label="What do you play?" name="game">
                        <x-ui.select name="game" placeholder="Any game">
                            @foreach ($games as $game)
                                <option value="{{ $game->slug }}" @selected(($filters['game'] ?? null) === $game->slug)>
                                    {{ $game->name }}
                                </option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Area" name="area">
                        <x-ui.select name="area" placeholder="Anywhere">
                            @foreach ($areas as $area)
                                <option value="{{ $area }}" @selected(($filters['area'] ?? null) === $area)>
                                    {{ $area }}
                                </option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Date" name="date">
                        <x-ui.input
                            name="date"
                            type="date"
                            :value="$filters['date'] ?? null"
                            :min="now()->toDateString()"
                            :max="now()->addDays(config('booking.max_advance_days'))->toDateString()"
                        />
                    </x-ui.field>

                    <x-ui.button type="submit" variant="primary" icon="search" size="lg">Search</x-ui.button>
                </div>

                {{-- <details> rather than a JS drawer: native, keyboard
                     accessible, works with JS off, and stays open on reload if
                     the customer is actually using these filters. --}}
                <details class="search-refine" @if (filled($filters['time'] ?? null) || filled($filters['duration'] ?? null) || filled($filters['min_price'] ?? null) || filled($filters['max_price'] ?? null)) open @endif>
                    <summary class="search-refine-toggle">
                        <x-ui.icon name="filter" :size="14" />
                        <span>Time &amp; price</span>
                        <x-ui.icon name="chevron-down" :size="14" class="search-refine-caret" />
                    </summary>

                    <div class="search-row-secondary">
                        <x-ui.field label="Around" name="time" hint="Optional">
                            <x-ui.input name="time" type="time" :value="$filters['time'] ?? null" step="900" />
                        </x-ui.field>

                        <x-ui.field label="For how long" name="duration">
                            <x-ui.select name="duration" placeholder="Any length">
                                @foreach ([30, 60, 90, 120, 180] as $minutes)
                                    <option value="{{ $minutes }}" @selected((int) ($filters['duration'] ?? 0) === $minutes)>
                                        {{ \App\Support\Money::duration($minutes) }}
                                    </option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>

                        <x-ui.field label="Min price" name="min_price">
                            <x-ui.input name="min_price" type="number" min="0" step="50"
                                :value="$filters['min_price'] ?? null" prefix="Rs." :placeholder="$priceRange['min']" />
                        </x-ui.field>

                        <x-ui.field label="Max price" name="max_price">
                            <x-ui.input name="max_price" type="number" min="0" step="50"
                                :value="$filters['max_price'] ?? null" prefix="Rs." :placeholder="$priceRange['max']" />
                        </x-ui.field>
                    </div>
                </details>
            </form>
        </div>
    </section>

    <div class="result-bar">
        <div class="stack-1">
            <h2 class="h3">
                <span data-count-up>{{ $businesses->total() }}</span>
                {{ Str::plural('venue', $businesses->total()) }}
                @if (filled($filters['date'] ?? null))
                    with availability on {{ $date->format('D j M') }}
                @endif
            </h2>
            <p class="text-sm text-muted">
                Availability shown for {{ $date->isToday() ? 'today' : $date->format('D j M') }}
            </p>
        </div>

        @if ($hasFilters)
            <div class="active-filters">
                @foreach ($filters as $key => $value)
                    @continue(blank($value))
                    <span class="filter-chip">
                        {{ str_replace('_', ' ', $key) }}: {{ $value }}
                        <a href="{{ request()->fullUrlWithQuery([$key => null]) }}"
                           aria-label="Remove {{ str_replace('_', ' ', $key) }} filter">
                            <x-ui.icon name="x" :size="11" />
                        </a>
                    </span>
                @endforeach

                <a href="{{ route('home') }}" class="link text-sm">Clear all</a>
            </div>
        @endif
    </div>

    @if ($businesses->isEmpty())
        {{-- SRS 9.16: never a blank page. Say what happened and how to widen. --}}
        <x-ui.card>
            <x-ui.empty-state icon="search" title="No venues match those filters">
                @if (filled($filters['date'] ?? null))
                    Nothing is free at that time. Try another date, a shorter booking,
                    or clear the time filter to see everything.
                @else
                    Try a different area or game, or clear the filters to see every venue.
                @endif

                <x-slot:action>
                    <x-ui.button :href="route('home')" variant="primary">Show all venues</x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="venue-grid" data-reveal-group>
            @foreach ($businesses as $business)
                @include('site.partials.venue-card', [
                    'business' => $business,
                    'availability' => $availability,
                    'date' => $date,
                    'reveal' => true,
                ])
            @endforeach
        </div>

        {{ $businesses->links() }}
    @endif
</x-app-layout>
