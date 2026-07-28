@props([
    'data' => [],       // [['label' => string, 'total' => int], ...]
    'height' => 200,
    'color' => 'var(--color-brand-500)',
])

@php
    $rows = collect($data);
    $max = max(1, (int) $rows->max('total'));
@endphp

{{--
    Horizontal bars. Horizontal rather than vertical because the labels here are
    category and area names -- rotated text under vertical bars is the classic
    way to make a chart unreadable.
--}}
@if ($rows->isEmpty())
    <p class="text-muted text-sm">No data for this period.</p>
@else
    <div class="chart-bars">
        @foreach ($rows as $row)
            <div class="chart-bar-row">
                <span class="chart-bar-label" title="{{ $row['label'] }}">{{ $row['label'] }}</span>
                <span class="chart-bar-track">
                    <span
                        class="chart-bar-fill"
                        style="width: {{ max(1, round(($row['total'] / $max) * 100, 1)) }}%; background: {{ $color }};"
                    ></span>
                </span>
                <span class="chart-bar-value">{{ number_format($row['total']) }}</span>
            </div>
        @endforeach
    </div>
@endif
