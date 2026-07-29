@props([
    'business' => null,
    'name' => null,
    'id' => null,
    'games' => [],
    'morph' => false,   // tag for the card -> venue-page view transition
])

{{--
    Generated venue artwork. See App\Support\VenueCrest for why this exists and
    how the scheme/motif are chosen.

    Rendered as inline SVG rather than a data-URI background so the motif can
    inherit the scheme colours directly and stays crisp at any size -- a crest
    is used at 168px on a card and full-bleed on a venue hero.

    Every id here is suffixed with the seed. Two crests on the same page would
    otherwise share gradient ids, and the second would silently inherit the
    first's colours.
--}}

@php
    use App\Support\VenueCrest;

    $crestId = $id ?? $business?->id ?? 0;
    $crestName = $name ?? $business?->name ?? 'Venue';

    $slugs = $games;

    if (! $slugs && $business?->relationLoaded('businessGames')) {
        $slugs = $business->businessGames
            ->map(fn ($businessGame) => $businessGame->game?->slug)
            ->filter()
            ->values()
            ->all();
    }

    $crest = VenueCrest::for($crestId, $crestName, $slugs);
    $uid = 'crest-'.$crest['seed'];
@endphp

<svg
    {{ $attributes->merge(['class' => 'crest']) }}
    viewBox="0 0 320 200"
    preserveAspectRatio="xMidYMid slice"
    role="img"
    aria-label="{{ $crestName }}"
    @if ($morph) data-morph-target style="view-transition-name: venue-crest" @endif
>
    <defs>
        <radialGradient id="{{ $uid }}-light" cx="18%" cy="0%" r="95%">
            <stop offset="0%" stop-color="{{ $crest['light'] }}" stop-opacity="0.42" />
            <stop offset="55%" stop-color="{{ $crest['light'] }}" stop-opacity="0.08" />
            <stop offset="100%" stop-color="{{ $crest['base'] }}" stop-opacity="0" />
        </radialGradient>

        <pattern id="{{ $uid }}-grid" width="26" height="26" patternUnits="userSpaceOnUse">
            <path d="M26 0H0V26" fill="none" stroke="{{ $crest['light'] }}" stroke-opacity="0.09" stroke-width="1" />
        </pattern>
    </defs>

    <rect width="320" height="200" fill="{{ $crest['base'] }}" />
    <rect width="320" height="200" fill="url(#{{ $uid }}-grid)" />
    <rect width="320" height="200" fill="url(#{{ $uid }}-light)" />

    {{-- The motif: painted court markings, drawn not filled, so it reads as
         line-work on a surface rather than as a logo pasted on top. --}}
    <g
        transform="translate({{ $crest['shift'] }} 0) rotate({{ $crest['rotation'] }} 160 100)"
        fill="none"
        stroke="{{ $crest['light'] }}"
        stroke-opacity="0.5"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
    >
        @switch ($crest['motif'])
            @case ('cue')
                {{-- Table cushion, pocket arcs and a rack triangle. --}}
                <rect x="72" y="46" width="176" height="108" rx="8" />
                <path d="M72 76a30 30 0 0 0 0 48M248 76a30 30 0 0 1 0 48" stroke-opacity="0.3" />
                <path d="M160 68l30 52h-60z" />
                <circle cx="160" cy="100" r="6" stroke-opacity="0.8" />
                @break

            @case ('pitch')
                {{-- Pitch outline, halfway line, centre circle, goal mouths. --}}
                <rect x="52" y="40" width="216" height="120" rx="4" />
                <path d="M160 40v120" />
                <circle cx="160" cy="100" r="30" />
                <path d="M52 74h30v52H52M268 74h-30v52h30" stroke-opacity="0.4" />
                @break

            @case ('court')
                {{-- Court box with a net across the middle and service lines. --}}
                <rect x="66" y="42" width="188" height="116" rx="3" />
                <path d="M160 42v116" stroke-opacity="0.85" stroke-dasharray="5 5" />
                <path d="M66 78h188M66 122h188" stroke-opacity="0.35" />
                @break

            @case ('lane')
                {{-- Parallel run-up lanes converging on a target. --}}
                <path d="M96 168L128 36M224 168L192 36" />
                <path d="M112 100h96M104 134h112" stroke-opacity="0.35" />
                <path d="M148 36h24" stroke-opacity="0.8" />
                @break

            @case ('screen')
                {{-- Screen, stand, and a scan-line field. --}}
                <rect x="76" y="44" width="168" height="96" rx="6" />
                <path d="M140 140h40M132 158h56" stroke-opacity="0.6" />
                <path d="M96 70h128M96 92h128M96 114h84" stroke-opacity="0.22" />
                @break

            @default
                {{-- `hall`: a generic floor plan. This is the case that makes the
                     component survive new categories -- a banquet hall or a
                     conference room lands here and still gets real artwork. --}}
                <rect x="60" y="40" width="200" height="120" rx="4" />
                <path d="M60 100h200M160 40v120" stroke-opacity="0.3" />
                <circle cx="110" cy="70" r="12" stroke-opacity="0.55" />
                <circle cx="210" cy="70" r="12" stroke-opacity="0.55" />
                <circle cx="110" cy="130" r="12" stroke-opacity="0.55" />
                <circle cx="210" cy="130" r="12" stroke-opacity="0.55" />
        @endswitch
    </g>
</svg>
