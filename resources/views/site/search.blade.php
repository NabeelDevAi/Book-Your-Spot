<x-app-layout title="Find a spot">
    {{-- The hero sits outside .container so its background spans the viewport. --}}
    @push('head')
        <style>.app-main { padding-top: 0; }</style>
    @endpush

    <div class="search-hero" style="margin-left: calc(50% - 50vw); margin-right: calc(50% - 50vw); padding-inline: calc(50vw - 50%);">
        <div class="container" style="padding-inline: 0;">
            <h1 class="search-hero-title">Find a spot to play</h1>
            <p class="search-hero-subtitle">
                Snooker, futsal, padel, PS5 and more across Karachi — real prices, live availability.
            </p>

            <form method="GET" action="{{ route('home') }}" class="search-panel">
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

                    <x-ui.button type="submit" variant="primary" icon="search">Search</x-ui.button>
                </div>

                <div class="search-row-secondary">
                    <x-ui.field label="Around" name="time" hint="Optional">
                        <x-ui.input name="time" type="time" :value="$filters['time'] ?? null" step="900" />
                    </x-ui.field>

                    <x-ui.field label="For how long" name="duration">
                        <x-ui.select name="duration" placeholder="Any length">
                            @foreach ([30, 60, 90, 120, 180] as $minutes)
                                <option value="{{ $minutes }}"
                                    @selected((int) ($filters['duration'] ?? 0) === $minutes)>
                                    {{ \App\Support\Money::duration($minutes) }}
                                </option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Min price" name="min_price">
                        <x-ui.input
                            name="min_price" type="number" min="0" step="50"
                            :value="$filters['min_price'] ?? null"
                            prefix="Rs."
                            :placeholder="$priceRange['min']"
                        />
                    </x-ui.field>

                    <x-ui.field label="Max price" name="max_price">
                        <x-ui.input
                            name="max_price" type="number" min="0" step="50"
                            :value="$filters['max_price'] ?? null"
                            prefix="Rs."
                            :placeholder="$priceRange['max']"
                        />
                    </x-ui.field>

                    @if ($hasFilters)
                        <x-ui.button :href="route('home')" variant="ghost">Clear all</x-ui.button>
                    @else
                        <span></span>
                    @endif
                </div>
            </form>
        </div>
    </div>

    <div class="result-bar">
        <div>
            <h2 class="h3">
                {{ $businesses->total() }} {{ Str::plural('venue', $businesses->total()) }}
                @if (filled($filters['date'] ?? null))
                    with availability on {{ \Illuminate\Support\Carbon::parse($filters['date'])->format('D j M') }}
                @endif
            </h2>
        </div>

        @if ($hasFilters)
            <div class="active-filters">
                @foreach ($filters as $key => $value)
                    @continue(blank($value))
                    <span class="filter-chip">
                        {{ str_replace('_', ' ', $key) }}: {{ $value }}
                        <a href="{{ request()->fullUrlWithQuery([$key => null]) }}" aria-label="Remove filter">
                            <x-ui.icon name="x" :size="11" />
                        </a>
                    </span>
                @endforeach
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
        <div class="venue-grid">
            @foreach ($businesses as $business)
                @include('site.partials.venue-card', ['business' => $business])
            @endforeach
        </div>

        {{ $businesses->links() }}
    @endif
</x-app-layout>
