@props([
    'icon' => 'search',
    'title' => 'Nothing here yet',
])

{{--
    SRS 9.16: an empty result set must always explain itself and offer a way
    forward -- never a blank panel.
--}}

<div {{ $attributes->merge(['class' => 'empty-state']) }}>
    <div class="empty-state-icon"><x-ui.icon :name="$icon" :size="22" /></div>
    <h3 class="empty-state-title">{{ $title }}</h3>

    @if ($slot->isNotEmpty())
        <p class="empty-state-text">{{ $slot }}</p>
    @endif

    @isset($action)
        <div class="cluster-2" style="margin-top: var(--space-2);">{{ $action }}</div>
    @endisset
</div>
