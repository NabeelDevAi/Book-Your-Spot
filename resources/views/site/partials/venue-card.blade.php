@php
    $cheapest = $business->spots->sortBy('price_amount')->first();
    $cover = $business->images->first();
@endphp

<a href="{{ route('businesses.show', $business) }}" class="venue-card" @if ($reveal ?? false) data-reveal @endif>
    <div class="venue-card-media">
        @if ($cover)
            <img src="{{ $cover->url() }}" alt="{{ $business->name }}" loading="lazy">
        @else
            <x-ui.icon name="image" :size="28" />
        @endif

        {{-- Price is always visible, including to guests. Pricing transparency
             is a stated goal of the platform (SRS 9.13). --}}
        @if ($cheapest)
            <span class="venue-card-price">from {{ $cheapest->rateLabel() }}</span>
        @endif
    </div>

    <div class="venue-card-body">
        <h3 class="venue-card-name">{{ $business->name }}</h3>

        <span class="venue-card-meta">
            <x-ui.icon name="map-pin" :size="13" /> {{ $business->area }}
        </span>

        <span class="venue-card-meta">
            <x-ui.icon name="clock" :size="13" />
            {{ Str::limit($business->hours()->summary(), 46) }}
        </span>

        <div class="venue-card-tags">
            @foreach ($business->businessGames as $businessGame)
                <span class="tag">{{ $businessGame->game->name }}</span>
            @endforeach
        </div>
    </div>

    <div class="venue-card-footer">
        <span class="text-sm text-muted">
            {{ $business->spots->count() }} {{ Str::plural('spot', $business->spots->count()) }} available
        </span>
        <span class="link text-sm">View <x-ui.icon name="chevron-right" :size="12" /></span>
    </div>
</a>
