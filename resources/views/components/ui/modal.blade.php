@props([
    'id',
    'title' => null,
    'subtitle' => null,
    'width' => null,        // narrow | wide
])

{{--
    Native <dialog>: focus trapping, Escape-to-close and top-layer stacking
    come free. Open it with <button data-modal-open="{{ $id }}">.
--}}

<dialog id="{{ $id }}" {{ $attributes->merge(['class' => 'modal'.($width ? ' modal-'.$width : '')]) }}>
    @if ($title)
        <div class="modal-header">
            <div>
                <h2 class="modal-title">{{ $title }}</h2>
                @if ($subtitle)<p class="modal-subtitle">{{ $subtitle }}</p>@endif
            </div>
            <button type="button" class="modal-close" data-modal-close aria-label="Close">
                <x-ui.icon name="x" :size="18" />
            </button>
        </div>
    @endif

    <div class="modal-body">
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="modal-footer">{{ $footer }}</div>
    @endisset
</dialog>
