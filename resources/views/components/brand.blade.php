@props([
    'href' => null,
    'context' => null,      // owner | admin -- tints the mark so consoles are distinguishable
    'label' => true,
])

@php
    $tag = $href ? 'a' : 'div';
    $markClass = 'brand-mark'.($context ? ' is-'.$context : '');
@endphp

<{{ $tag }} @if ($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'brand']) }}>
    <span class="{{ $markClass }}">B</span>

    @if ($label)
        <span>
            BookYourSpot
            @if ($context)
                <span class="text-muted" style="font-weight: var(--weight-normal);">{{ ucfirst($context) }}</span>
            @endif
        </span>
    @endif
</{{ $tag }}>
