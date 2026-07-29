{{--
    Three-state theme control: system, light, dark.

    A segmented control rather than a single sun/moon button, because a
    two-state toggle cannot express "follow my OS" -- and that is the default
    the majority of people actually want. The state is server-rendered, so the
    correct segment is already active before JS boots.
--}}

@props(['compact' => false])

@php
    $current = in_array(request()->cookie('theme'), ['light', 'dark'], true)
        ? request()->cookie('theme')
        : 'system';

    $options = [
        'system' => ['icon' => 'monitor', 'label' => 'Match system'],
        'light' => ['icon' => 'sun', 'label' => 'Light'],
        'dark' => ['icon' => 'moon', 'label' => 'Dark'],
    ];
@endphp

<div
    {{ $attributes->merge(['class' => 'theme-toggle'.($compact ? ' is-compact' : '')]) }}
    data-theme-toggle
    data-theme-state="{{ $current }}"
    role="radiogroup"
    aria-label="Colour theme"
>
    @foreach ($options as $value => $option)
        <button
            type="button"
            class="theme-toggle-option {{ $current === $value ? 'is-active' : '' }}"
            data-theme-option="{{ $value }}"
            role="radio"
            aria-checked="{{ $current === $value ? 'true' : 'false' }}"
            title="{{ $option['label'] }}"
        >
            <x-ui.icon :name="$option['icon']" :size="15" />
            <span class="sr-only">{{ $option['label'] }}</span>
        </button>
    @endforeach
</div>
