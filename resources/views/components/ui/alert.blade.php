@props([
    'variant' => 'info',    // success | warning | danger | info | neutral
    'title' => null,
    'dismissible' => false,
    'autoDismiss' => null,  // milliseconds
    'icon' => true,
])

@php
    $iconName = match ($variant) {
        'success' => 'check-circle',
        'warning' => 'alert',
        'danger'  => 'x-circle',
        default   => 'info',
    };
@endphp

<div
    class="alert alert-{{ $variant }}"
    role="{{ in_array($variant, ['danger', 'warning']) ? 'alert' : 'status' }}"
    @if ($autoDismiss) data-auto-dismiss="{{ $autoDismiss }}" @endif
    {{ $attributes }}
>
    @if ($icon)
        <span class="alert-icon"><x-ui.icon :name="$iconName" :size="18" /></span>
    @endif

    <div class="alert-body">
        @if ($title)<div class="alert-title">{{ $title }}</div>@endif
        {{ $slot }}
    </div>

    @if ($dismissible)
        <button type="button" class="alert-close" data-alert-dismiss aria-label="Dismiss">
            <x-ui.icon name="x" :size="16" />
        </button>
    @endif
</div>
