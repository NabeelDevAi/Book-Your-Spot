@props([
    'status' => null,       // a domain status: pending, confirmed, no_show, active, ...
    'variant' => null,      // or an explicit tone: success | warning | danger | info | neutral
    'dot' => false,
])

@php
    $class = $status
        ? 'badge-status-'.$status
        : 'badge-'.($variant ?? 'neutral');

    // Domain statuses are stored snake_case; render them readably.
    $text = $slot->isNotEmpty()
        ? null
        : ucfirst(str_replace('_', ' ', (string) $status));
@endphp

<span {{ $attributes->merge(['class' => 'badge '.$class]) }}>
    @if ($dot)<span class="badge-dot"></span>@endif
    {{ $text ?? $slot }}
</span>
