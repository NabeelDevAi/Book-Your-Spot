@props([
    'variant' => 'primary',   // primary | secondary | ghost | danger | danger-outline | success | accent
    'size' => null,           // sm | lg | null
    'href' => null,
    'type' => 'submit',
    'icon' => null,
    'iconAfter' => null,
    'block' => false,
])

@php
    $classes = collect(['btn', 'btn-'.$variant])
        ->when($size, fn ($c) => $c->push('btn-'.$size))
        ->when($block, fn ($c) => $c->push('btn-block'))
        ->implode(' ');

    $iconSize = $size === 'sm' ? 14 : 16;
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-ui.icon :name="$icon" :size="$iconSize" />@endif
        {{ $slot }}
        @if ($iconAfter)<x-ui.icon :name="$iconAfter" :size="$iconSize" />@endif
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-ui.icon :name="$icon" :size="$iconSize" />@endif
        {{ $slot }}
        @if ($iconAfter)<x-ui.icon :name="$iconAfter" :size="$iconSize" />@endif
    </button>
@endif
