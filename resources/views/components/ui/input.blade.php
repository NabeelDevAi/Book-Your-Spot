@props([
    'name' => null,
    'type' => 'text',
    'value' => null,
    'affix' => null,        // trailing text, e.g. "min"
    'prefix' => null,       // leading text, e.g. "Rs."
    'bag' => 'default',     // named error bag (the profile forms use their own)
])

@php
    $bagErrors = $errors->getBag($bag);
    $hasError = $name && $bagErrors->has($name);
    $classes = 'input'.($hasError ? ' has-error' : '');

    // Guard the null-name case: old(null) returns the ENTIRE old-input array
    // rather than the default, which blows up when echoed into the value
    // attribute. Nameless inputs (read-only display fields) are legitimate.
    $resolved = $name ? old($name, $value) : $value;
@endphp

@if ($prefix || $affix)
    <div class="input-group">
        @if ($prefix)<span class="input-affix">{{ $prefix }}</span>@endif
        <input
            type="{{ $type }}"
            @if ($name) name="{{ $name }}" id="{{ $attributes->get('id', $name) }}" @endif
            value="{{ $resolved }}"
            {{ $attributes->merge(['class' => $classes]) }}
        >
        @if ($affix)<span class="input-affix">{{ $affix }}</span>@endif
    </div>
@else
    <input
        type="{{ $type }}"
        @if ($name) name="{{ $name }}" id="{{ $attributes->get('id', $name) }}" @endif
        value="{{ $resolved }}"
        {{ $attributes->merge(['class' => $classes]) }}
    >
@endif
