@props([
    'name' => '',
    'size' => null,     // sm | lg
])

@php
    // Two initials from the first and last word of the name.
    $words = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $initials = match (count($words)) {
        0 => '?',
        1 => mb_strtoupper(mb_substr($words[0], 0, 2)),
        default => mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr(end($words), 0, 1)),
    };
@endphp

<span
    {{ $attributes->merge(['class' => 'avatar'.($size ? ' avatar-'.$size : '')]) }}
    title="{{ $name }}"
    aria-hidden="true"
>{{ $initials }}</span>
