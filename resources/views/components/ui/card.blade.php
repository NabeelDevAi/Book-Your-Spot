@props([
    'title' => null,
    'subtitle' => null,
    'flush' => false,       // remove body padding (tables sit flush)
])

<div {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title || isset($actions))
        <div class="card-header">
            <div>
                @if ($title)<h2 class="card-title">{{ $title }}</h2>@endif
                @if ($subtitle)<p class="card-subtitle">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)
                <div class="cluster-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif

    <div class="card-body @if ($flush) card-body-flush @endif">
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="card-footer">{{ $footer }}</div>
    @endisset
</div>
