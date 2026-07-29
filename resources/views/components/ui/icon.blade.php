@props([
    'name',
    'size' => 16,
])

{{--
    Inline SVG icon set. Kept as one component so we ship no icon font and
    no external requests, and every icon inherits currentColor.
--}}

@php
    $paths = [
        'calendar'   => '<rect x="2.5" y="3.5" width="15" height="14" rx="2"/><path d="M2.5 8h15M6.5 1.5v4M13.5 1.5v4"/>',
        'clock'      => '<circle cx="10" cy="10" r="7.5"/><path d="M10 5.5V10l3 2"/>',
        'check'      => '<path d="M4 10.5l4 4 8-9"/>',
        'check-circle' => '<circle cx="10" cy="10" r="7.5"/><path d="M6.5 10l2.5 2.5 4.5-5"/>',
        'x'          => '<path d="M5 5l10 10M15 5L5 15"/>',
        'x-circle'   => '<circle cx="10" cy="10" r="7.5"/><path d="M7.5 7.5l5 5M12.5 7.5l-5 5"/>',
        'alert'      => '<path d="M10 6.5v4m0 3h.01M8.6 2.6 1.7 14.4a1.6 1.6 0 0 0 1.4 2.4h13.8a1.6 1.6 0 0 0 1.4-2.4L11.4 2.6a1.6 1.6 0 0 0-2.8 0Z"/>',
        'info'       => '<circle cx="10" cy="10" r="7.5"/><path d="M10 9v4.5M10 6.5h.01"/>',
        'bell'       => '<path d="M15 7a5 5 0 1 0-10 0c0 5-2 6-2 6h14s-2-1-2-6M11.7 16a2 2 0 0 1-3.4 0"/>',
        'search'     => '<circle cx="9" cy="9" r="6"/><path d="M13.5 13.5 17 17"/>',
        'filter'     => '<path d="M2.5 4.5h15M5 10h10M8 15.5h4"/>',
        'user'       => '<circle cx="10" cy="6.5" r="3.5"/><path d="M3.5 17c0-3.3 2.9-5.5 6.5-5.5s6.5 2.2 6.5 5.5"/>',
        'users'      => '<circle cx="7.5" cy="6.5" r="3"/><path d="M2 16.5c0-2.8 2.5-4.5 5.5-4.5s5.5 1.7 5.5 4.5"/><path d="M13.5 4.2a3 3 0 0 1 0 5.6M15 12.3c1.9.5 3 1.9 3 4.2"/>',
        'building'   => '<path d="M3.5 17.5v-13a1 1 0 0 1 1-1h7a1 1 0 0 1 1 1v13M12.5 8.5h3a1 1 0 0 1 1 1v8M2 17.5h16M6.5 6.5h3M6.5 9.5h3M6.5 12.5h3"/>',
        'grid'       => '<rect x="2.5" y="2.5" width="6" height="6" rx="1"/><rect x="11.5" y="2.5" width="6" height="6" rx="1"/><rect x="2.5" y="11.5" width="6" height="6" rx="1"/><rect x="11.5" y="11.5" width="6" height="6" rx="1"/>',
        'layers'     => '<path d="M10 2.5 2.5 6.5 10 10.5l7.5-4L10 2.5ZM2.5 10.5 10 14.5l7.5-4M2.5 14 10 18l7.5-4"/>',
        'map-pin'    => '<path d="M10 18s6-4.8 6-9a6 6 0 1 0-12 0c0 4.2 6 9 6 9Z"/><circle cx="10" cy="9" r="2.25"/>',
        'phone'      => '<path d="M6.5 2.5 8 6l-2 1.5a10 10 0 0 0 4.5 4.5L12 10l3.5 1.5v3.5a1.5 1.5 0 0 1-1.7 1.5C7.7 15.9 4.1 12.3 3 6.2A1.5 1.5 0 0 1 4.5 4.5h2Z"/>',
        'settings'   => '<circle cx="10" cy="10" r="2.75"/><path d="M15.9 12.2a1.4 1.4 0 0 0 .3 1.5l.1.1a1.6 1.6 0 1 1-2.3 2.3l-.1-.1a1.4 1.4 0 0 0-2.4 1v.2a1.6 1.6 0 1 1-3.2 0v-.1a1.4 1.4 0 0 0-2.4-1l-.1.1a1.6 1.6 0 1 1-2.3-2.3l.1-.1a1.4 1.4 0 0 0-1-2.4h-.2a1.6 1.6 0 1 1 0-3.2h.1a1.4 1.4 0 0 0 1-2.4l-.1-.1a1.6 1.6 0 1 1 2.3-2.3l.1.1a1.4 1.4 0 0 0 2.4-1v-.2a1.6 1.6 0 1 1 3.2 0v.1a1.4 1.4 0 0 0 2.4 1l.1-.1a1.6 1.6 0 1 1 2.3 2.3l-.1.1a1.4 1.4 0 0 0 1 2.4h.2a1.6 1.6 0 1 1 0 3.2h-.1a1.4 1.4 0 0 0-1.3.9Z"/>',
        'logout'     => '<path d="M7.5 17.5h-3a1.5 1.5 0 0 1-1.5-1.5V4a1.5 1.5 0 0 1 1.5-1.5h3M13 14l4-4-4-4M17 10H7.5"/>',
        'plus'       => '<path d="M10 4v12M4 10h12"/>',
        'edit'       => '<path d="M12.5 3.5 16 7M3 17l.8-3.4L13.4 4a1.5 1.5 0 0 1 2.1 0l.5.5a1.5 1.5 0 0 1 0 2.1L6.4 16.2 3 17Z"/>',
        'trash'      => '<path d="M3.5 5.5h13M8 5.5V4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v1.5M5.5 5.5 6 16a1.5 1.5 0 0 0 1.5 1.4h5A1.5 1.5 0 0 0 14 16l.5-10.5M8.5 8.5v6M11.5 8.5v6"/>',
        'chevron-down'  => '<path d="M5 7.5 10 12.5 15 7.5"/>',
        'chevron-right' => '<path d="M7.5 4.5 13 10l-5.5 5.5"/>',
        'chevron-left'  => '<path d="M12.5 4.5 7 10l5.5 5.5"/>',
        'arrow-right'   => '<path d="M3.5 10h13M11.5 5l5 5-5 5"/>',
        'arrow-left'    => '<path d="M16.5 10h-13M8.5 5l-5 5 5 5"/>',
        'external'      => '<path d="M11 3.5h5.5V9M16.5 3.5 9 11M14 11.5V16a1.5 1.5 0 0 1-1.5 1.5h-8A1.5 1.5 0 0 1 3 16V8a1.5 1.5 0 0 1 1.5-1.5H9"/>',
        'shield'        => '<path d="M10 2.5 3.5 5v5c0 4 2.8 6.9 6.5 8 3.7-1.1 6.5-4 6.5-8V5L10 2.5Z"/>',
        'chart'         => '<path d="M3 17h14M6 14V9M10 14V4.5M14 14v-7"/>',
        'ban'           => '<circle cx="10" cy="10" r="7.5"/><path d="M4.7 4.7l10.6 10.6"/>',
        'refresh'       => '<path d="M16.5 8.5A6.5 6.5 0 0 0 5 6M3.5 11.5A6.5 6.5 0 0 0 15 14M3.5 4v4.5H8M16.5 16v-4.5H12"/>',
        'lock'          => '<rect x="4" y="8.5" width="12" height="9" rx="1.5"/><path d="M6.5 8.5V6a3.5 3.5 0 1 1 7 0v2.5"/>',
        'image'         => '<rect x="2.5" y="3.5" width="15" height="13" rx="2"/><circle cx="7" cy="8" r="1.5"/><path d="m3 14 4-3.5 3.5 3 3-2.5L17.5 14"/>',
        'sparkle'       => '<path d="M10 2.5 11.8 7.7 17 9.5l-5.2 1.8L10 16.5l-1.8-5.2L3 9.5l5.2-1.8L10 2.5Z"/>',
        'wallet'        => '<path d="M2.5 6.5A2 2 0 0 1 4.5 4.5h10a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-10a2 2 0 0 1-2-2Z"/><path d="M2.5 8h13a2 2 0 0 1 2 2v1.5a2 2 0 0 1-2 2h-2a2.25 2.25 0 0 1 0-4.5h4"/>',
        'credit-card'   => '<rect x="2" y="4.5" width="16" height="11" rx="2"/><path d="M2 8.5h16M5 12.5h3"/>',
        'arrow-up-right' => '<path d="M6 14 14 6M7.5 6H14v6.5"/>',
        'arrow-down-left' => '<path d="M14 6 6 14M12.5 14H6V7.5"/>',
        'flag'          => '<path d="M4.5 17.5V3.5M4.5 4.2h9.8l-1.7 3.3 1.7 3.3H4.5"/>',
        'sun'           => '<circle cx="10" cy="10" r="3.5"/><path d="M10 1.8v2.1M10 16.1v2.1M3.2 3.2l1.5 1.5M15.3 15.3l1.5 1.5M1.8 10h2.1M16.1 10h2.1M3.2 16.8l1.5-1.5M15.3 4.7l1.5-1.5"/>',
        'moon'          => '<path d="M17 11.6A7.5 7.5 0 0 1 8.4 3a7.5 7.5 0 1 0 8.6 8.6Z"/>',
        'monitor'       => '<rect x="2" y="3.5" width="16" height="11" rx="1.5"/><path d="M7 17.5h6M10 14.5v3"/>',
    ];

    $path = $paths[$name] ?? $paths['info'];
@endphp

<svg
    {{ $attributes->merge(['class' => 'icon']) }}
    width="{{ $size }}"
    height="{{ $size }}"
    viewBox="0 0 20 20"
    fill="none"
    stroke="currentColor"
    stroke-width="1.5"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
    focusable="false"
>{!! $path !!}</svg>
