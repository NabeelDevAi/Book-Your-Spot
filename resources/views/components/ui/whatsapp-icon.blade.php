@props([
    'size' => 16,
])

{{--
    The one brand mark in the app. Every other icon in x-ui.icon is a
    stroked outline in currentColor by design (no icon font, nothing
    borrowed) -- this one is deliberately a solid glyph instead, because a
    WhatsApp share button that doesn't look like WhatsApp doesn't get
    recognised or trusted at a glance.
--}}

<svg
    {{ $attributes->merge(['class' => 'icon']) }}
    width="{{ $size }}"
    height="{{ $size }}"
    viewBox="0 0 24 24"
    fill="currentColor"
    aria-hidden="true"
    focusable="false"
><path d="M12.04 2C6.54 2 2.08 6.46 2.08 11.96c0 1.77.46 3.45 1.33 4.95L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22c5.5 0 9.96-4.46 9.96-9.96S17.54 2 12.04 2Zm0 18.2a8.24 8.24 0 0 1-4.2-1.15l-.3-.18-3.12.82.83-3.04-.2-.32a8.22 8.22 0 0 1-1.26-4.37c0-4.55 3.7-8.25 8.26-8.25 2.2 0 4.27.86 5.83 2.42a8.19 8.19 0 0 1 2.42 5.83c0 4.55-3.71 8.24-8.26 8.24Zm4.53-6.16c-.25-.12-1.47-.72-1.7-.81-.23-.08-.4-.12-.56.13-.17.24-.64.8-.78.97-.15.17-.29.19-.53.06-.25-.12-1.04-.38-1.99-1.22-.73-.66-1.23-1.46-1.37-1.71-.14-.25-.02-.38.11-.5.11-.11.25-.29.37-.43.12-.15.16-.25.24-.42.08-.17.04-.31-.02-.44-.06-.12-.56-1.36-.77-1.86-.2-.49-.41-.42-.56-.43h-.48c-.17 0-.44.06-.67.31-.23.25-.87.85-.87 2.08s.9 2.42 1.02 2.58c.12.17 1.77 2.7 4.28 3.79.6.26 1.06.42 1.43.53.6.19 1.14.16 1.57.1.48-.07 1.47-.6 1.68-1.18.21-.58.21-1.08.14-1.18-.06-.11-.23-.17-.48-.29Z"/></svg>
