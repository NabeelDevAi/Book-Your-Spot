<x-console-layout title="Categories" context="owner">
    <x-ui.page-header
        title="What can people play here?"
        description="Pick the categories your venue offers. You'll add the individual tables, courts or rooms next."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('owner.businesses.index') }}" class="link-muted">Venues</a>
            <span class="breadcrumb-sep">/</span>
            <a href="{{ route('owner.businesses.edit', $business) }}" class="link-muted">{{ $business->name }}</a>
            <span class="breadcrumb-sep">/</span>
            <span>Categories</span>
        </x-slot:breadcrumb>
    </x-ui.page-header>

    <x-owner.venue-status-banner :business="$business" />

    {{-- FR-2.3: categories come from the Admin-managed master list. Owners
         can't invent their own, which is what stops the filter fragmenting
         into "PS5" / "Playstation 5" / "PS 5". --}}
    <x-ui.alert variant="info" style="margin-bottom: var(--space-5);">
        Categories are managed centrally so customers searching for "PS5" find every venue
        that offers it. Missing one? Let us know and we'll add it to the list.
    </x-ui.alert>

    <form method="POST" action="{{ route('owner.businesses.games.update', $business) }}">
        @csrf
        @method('put')

        <x-ui.card>
            <div class="grid-3">
                @foreach ($games as $game)
                    @php
                        $businessGame = $businessGames[$game->id] ?? null;
                        $spotCount = $businessGame ? ($spotCounts[$businessGame->id] ?? 0) : 0;
                    @endphp

                    <label class="choice-card">
                        <input
                            type="checkbox"
                            name="games[]"
                            value="{{ $game->id }}"
                            @checked(in_array($game->id, old('games', $selected)))
                        >

                        <span>
                            <span class="choice-card-title">{{ $game->name }}</span>
                            @if ($spotCount > 0)
                                <span class="cell-secondary">
                                    {{ $spotCount }} {{ Str::plural('spot', $spotCount) }} —
                                    remove those first to unselect
                                </span>
                            @elseif ($game->description)
                                <span class="cell-secondary">{{ $game->description }}</span>
                            @endif
                        </span>
                    </label>
                @endforeach
            </div>
        </x-ui.card>

        <div class="form-actions" style="margin-top: var(--space-6);">
            <x-ui.button type="submit" variant="primary">Save categories</x-ui.button>
            <x-ui.button :href="route('owner.businesses.spots.index', $business)" variant="ghost">
                Skip to spots
            </x-ui.button>
        </div>
    </form>
</x-console-layout>
