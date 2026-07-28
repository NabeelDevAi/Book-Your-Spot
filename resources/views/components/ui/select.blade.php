@props([
    'name' => null,
    'options' => [],        // ['value' => 'Label'] or a collection of ['value'=>, 'label'=>]
    'selected' => null,
    'placeholder' => null,
])

@php
    $hasError = $name && $errors->has($name);
    $classes = 'select'.($hasError ? ' has-error' : '');
    // See the note in ui/input: old(null) returns the whole old-input array.
    $current = $name ? old($name, $selected) : $selected;
@endphp

<select
    @if ($name) name="{{ $name }}" id="{{ $attributes->get('id', $name) }}" @endif
    {{ $attributes->merge(['class' => $classes]) }}
>
    @if ($placeholder)
        <option value="">{{ $placeholder }}</option>
    @endif

    @if ($slot->isNotEmpty())
        {{ $slot }}
    @else
        @foreach ($options as $value => $label)
            <option value="{{ $value }}" @selected((string) $current === (string) $value)>{{ $label }}</option>
        @endforeach
    @endif
</select>
