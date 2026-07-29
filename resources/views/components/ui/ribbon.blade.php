@props([
    'day' => null,
    'busy' => [],        // [['start' => Carbon, 'end' => Carbon, 'type' => 'booking'|'block'], ...]
    'open' => [],        // operating windows, same shape. Empty = open all day.
    'density' => 'standard',  // micro | standard | tall | dense
    'label' => null,     // row label, used by the dense multi-row variant
    'meta' => null,
    'scale' => false,    // render the hour scale underneath
    'now' => true,       // draw the current-time marker when day is today
])

{{--
    The availability ribbon. One day, one track: closed time is bare, open time
    is lit, taken time is in shadow, and a marker shows where "now" falls.

    Four densities, one component:
      micro     -- a venue card's at-a-glance strip
      standard  -- a spot row on the venue page
      tall      -- the interactive picker on the booking page
      dense     -- a labelled row in the owner's occupancy stack

    Geometry comes from App\Support\Ribbon so the midnight-crossing cases are
    tested rather than trusted. Everything below is presentation.
--}}

@php
    use App\Support\Ribbon;

    $day = $day ?? now();
    $busySegments = Ribbon::segments($day, $busy);
    $openSegments = Ribbon::segments($day, $open);
    $nowOffset = $now ? Ribbon::nowOffset($day) : null;

    // With no operating hours supplied, treat the whole day as open rather
    // than rendering a track that reads as "closed 24 hours".
    $litSegments = $openSegments ?: [['left' => 0, 'width' => 100, 'type' => 'open', 'label' => 'Open']];
@endphp

<div {{ $attributes->merge(['class' => 'ribbon is-'.$density]) }}>
    @if ($label)
        <div class="ribbon-label">
            <span class="ribbon-label-name">{{ $label }}</span>
            @if ($meta)<span class="ribbon-label-meta">{{ $meta }}</span>@endif
        </div>
    @endif

    <div class="ribbon-body">
        <div class="ribbon-track" role="img"
             aria-label="Availability for {{ $day->format('D j M') }}">

            {{-- Lit: the venue is open. --}}
            @foreach ($litSegments as $segment)
                <span class="ribbon-open"
                      style="left: {{ $segment['left'] }}%; width: {{ $segment['width'] }}%"></span>
            @endforeach

            {{-- Shadow: already taken. An owner's own block is hatched rather
                 than solid so downtime they created is never miscounted as
                 revenue -- the distinction the owner console depends on. --}}
            @foreach ($busySegments as $segment)
                <span class="ribbon-busy {{ $segment['type'] === 'block' ? 'is-block' : '' }}"
                      style="left: {{ $segment['left'] }}%; width: {{ $segment['width'] }}%"
                      title="{{ $segment['label'] }}"></span>
            @endforeach

            @if ($nowOffset !== null)
                <span class="ribbon-now" style="left: {{ $nowOffset }}%" title="Now"></span>
            @endif

            {{ $slot }}
        </div>

        @if ($scale)
            <div class="ribbon-scale" aria-hidden="true">
                @foreach (Ribbon::hourMarks() as $mark)
                    <span class="ribbon-scale-mark" style="left: {{ $mark['left'] }}%">{{ $mark['label'] }}</span>
                @endforeach
            </div>
        @endif
    </div>
</div>
