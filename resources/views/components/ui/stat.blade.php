@props([
    'label',
    'value',
    'meta' => null,
    'tone' => null,     // attention | danger
    'href' => null,
])

@php
    $classes = 'stat'.($tone ? ' is-'.$tone : '');
    $tag = $href ? 'a' : 'div';
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => $classes]) }}>
    <span class="stat-label">{{ $label }}</span>
    <span class="stat-value">{{ $value }}</span>
    @if ($meta)<span class="stat-meta">{{ $meta }}</span>@endif
</{{ $tag }}>
