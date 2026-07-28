@props([
    'name' => null,
    'value' => null,
    'rows' => 4,
    'bag' => 'default',
])

@php
    $hasError = $name && $errors->getBag($bag)->has($name);
    $classes = 'textarea'.($hasError ? ' has-error' : '');

    // See the note in ui/input: old(null) returns the whole old-input array.
    $fallback = $value ?? $slot;
    $resolved = $name ? old($name, $fallback) : $fallback;
@endphp

<textarea
    @if ($name) name="{{ $name }}" id="{{ $attributes->get('id', $name) }}" @endif
    rows="{{ $rows }}"
    {{ $attributes->merge(['class' => $classes]) }}
>{{ $resolved }}</textarea>
