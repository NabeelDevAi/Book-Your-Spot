@props([
    'title',
    'description' => null,
])

<div {{ $attributes->merge(['class' => 'page-header']) }}>
    <div>
        @isset($breadcrumb)
            <div class="breadcrumb" style="margin-bottom: var(--space-2);">{{ $breadcrumb }}</div>
        @endisset

        <h1 class="page-heading">{{ $title }}</h1>

        @if ($description)
            <p class="page-description">{{ $description }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="page-actions">{{ $actions }}</div>
    @endisset
</div>
