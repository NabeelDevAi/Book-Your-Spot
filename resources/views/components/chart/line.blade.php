@props([
    'series' => [],     // [['name' => string, 'color' => string, 'points' => [int, ...]], ...]
    'labels' => [],
    'height' => 180,
])

@php
    $sets = collect($series);
    $count = max(1, count($labels));

    // A flat-zero series would divide by zero and collapse the polyline onto
    // the baseline, so the max never drops below 1.
    $max = max(1, $sets->flatMap(fn ($s) => $s['points'])->max() ?? 1);

    $width = 600;
    $padding = 4;
    $usableHeight = $height - ($padding * 2);

    $pointsFor = function (array $points) use ($count, $width, $height, $padding, $usableHeight, $max) {
        if ($count === 1) {
            return "0,".($height - $padding)." {$width},".($height - $padding);
        }

        $step = $width / ($count - 1);

        return collect($points)->map(function ($value, $index) use ($step, $height, $padding, $usableHeight, $max) {
            $x = round($index * $step, 2);
            $y = round($height - $padding - (($value / $max) * $usableHeight), 2);

            return "{$x},{$y}";
        })->implode(' ');
    };
@endphp

@if ($sets->isEmpty())
    <p class="text-muted text-sm">No data for this period.</p>
@else
    <div class="chart-line">
        <svg viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" class="chart-line-svg" role="img"
             aria-label="Trend over the last {{ $count }} days">
            {{-- Gridlines give the eye something to measure against; without them
                 a polyline is just a shape. --}}
            @foreach ([0, 0.25, 0.5, 0.75, 1] as $fraction)
                <line
                    x1="0" x2="{{ $width }}"
                    y1="{{ round($padding + ($fraction * $usableHeight), 2) }}"
                    y2="{{ round($padding + ($fraction * $usableHeight), 2) }}"
                    stroke="var(--border-color)" stroke-width="1" vector-effect="non-scaling-stroke"
                />
            @endforeach

            @foreach ($sets as $set)
                <polyline
                    points="{{ $pointsFor($set['points']) }}"
                    fill="none"
                    stroke="{{ $set['color'] ?? 'var(--color-brand-500)' }}"
                    stroke-width="2"
                    stroke-linejoin="round"
                    stroke-linecap="round"
                    vector-effect="non-scaling-stroke"
                />
            @endforeach
        </svg>

        <div class="chart-axis">
            <span>{{ $labels[0] ?? '' }}</span>
            <span>{{ $labels[intdiv($count, 2)] ?? '' }}</span>
            <span>{{ $labels[$count - 1] ?? '' }}</span>
        </div>

        <div class="chart-legend">
            @foreach ($sets as $set)
                <span class="chart-legend-item">
                    <span class="chart-legend-swatch" style="background: {{ $set['color'] ?? 'var(--color-brand-500)' }};"></span>
                    {{ $set['name'] }}
                    <strong>{{ number_format(array_sum($set['points'])) }}</strong>
                </span>
            @endforeach
            <span class="chart-legend-item text-muted">peak {{ number_format($max) }}/day</span>
        </div>
    </div>
@endif
