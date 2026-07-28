@props([
    'label' => null,
    'name' => null,
    'for' => null,
    'hint' => null,
    'required' => false,
    'error' => null,
    'bag' => 'default',     // named error bag
])

{{--
    Wraps a control with its label, hint and validation error. Pass `name` and
    the error is pulled from the error bag automatically.

    <x-ui.field label="Contact number" name="contact_number" required>
        <x-ui.input name="contact_number" />
    </x-ui.field>
--}}

@php
    $id = $for ?? $name;
    $message = $error ?? ($name ? $errors->getBag($bag)->first($name) : null);
@endphp

<div {{ $attributes->merge(['class' => 'field']) }}>
    @if ($label)
        <label class="field-label" @if ($id) for="{{ $id }}" @endif>
            {{ $label }}@if ($required)<span class="required" aria-hidden="true">*</span>@endif
        </label>
    @endif

    {{ $slot }}

    @if ($hint && ! $message)
        <p class="field-hint">{{ $hint }}</p>
    @endif

    @if ($message)
        <p class="field-error">{{ $message }}</p>
    @endif
</div>
