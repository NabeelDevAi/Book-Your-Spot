@php
    $cheapest = $business->spots->sortBy('price_amount')->first();
    $cover = $business->images->firstWhere('media_type', 'image');
    $windows = $availability[$business->id] ?? [];
    $spotCount = $business->spots->count();
@endphp

<a href="{{ route('businesses.show', $business) }}" class="venue-card" data-morph
   @if ($reveal ?? false) data-reveal @endif>

    <div class="venue-card-media crest-frame has-overlay">
        {{-- A real photo when the owner has uploaded one, generated artwork
             when they have not. Never the broken-image glyph the old card fell
             back to -- with no photography in the product, that was every card
             on every page. --}}
        @if ($cover)
            <img src="{{ $cover->url() }}" alt="{{ $business->name }}" loading="lazy">
        @else
            <x-ui.crest :business="$business" morph />
        @endif

        <div class="venue-card-badges">
            @if ($windows !== [])
                <span class="venue-card-flag is-open">
                    <span class="venue-card-flag-dot"></span> Availability today
                </span>
            @else
                <span class="venue-card-flag">Fully booked today</span>
            @endif
        </div>
    </div>

    <div class="venue-card-body">
        <h3 class="venue-card-name">{{ $business->name }}</h3>

        <span class="venue-card-meta">
            <x-ui.icon name="map-pin" :size="14" /> {{ $business->area }}
        </span>

        {{-- The ribbon is the card's real content. Everything above it says
             which venue this is; this says whether you can actually get in,
             which is the question that brought the customer here. --}}
        <div class="venue-card-ribbon">
            <x-ui.ribbon :day="$date" :open="$windows" density="micro" />
            <span class="venue-card-ribbon-caption">
                @if ($windows !== [])
                    Free {{ $windows[0]['start']->format('g:i A') }}
                    @if (count($windows) > 1)
                        &amp; {{ count($windows) - 1 }} more
                    @else
                        – {{ $windows[0]['end']->format('g:i A') }}
                    @endif
                @else
                    Try another day
                @endif
            </span>
        </div>

        <div class="venue-card-tags">
            @foreach ($business->businessGames as $businessGame)
                <span class="tag tag-muted">{{ $businessGame->game->name }}</span>
            @endforeach
        </div>
    </div>

    <div class="venue-card-footer">
        <div class="venue-card-price">
            {{-- Price is always visible, including to guests. Pricing
                 transparency is a stated goal of the platform (SRS 9.13). --}}
            @if ($cheapest)
                <span class="venue-card-price-label">from</span>
                <span class="venue-card-price-value">{{ $cheapest->rateLabel() }}</span>
            @else
                <span class="venue-card-price-label">Pricing at the venue</span>
            @endif
        </div>

        <span class="venue-card-cta">
            {{ $spotCount }} {{ Str::plural('spot', $spotCount) }}
            <x-ui.icon name="arrow-right" :size="14" />
        </span>
    </div>
</a>
