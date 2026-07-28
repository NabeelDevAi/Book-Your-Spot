<x-console-layout title="Categories" context="admin">
    <x-ui.page-header
        title="Game categories"
        description="The master list owners pick from (FR-3.3)."
    />

    {{-- The reason this list is centralised at all. --}}
    <x-ui.alert variant="info" style="margin-bottom: var(--space-5);">
        Owners choose from this list and can't invent their own. That's what stops the taxonomy
        splitting into "PS5", "Playstation 5" and "PS 5" — which would quietly break the
        customer-facing category filter.
    </x-ui.alert>

    <div class="layout-with-aside">
        <x-ui.card flush>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th class="cell-numeric">Venues using it</th>
                            <th>Status</th>
                            <th class="cell-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($games as $game)
                            <tr class="{{ $game->isActive() ? '' : 'is-muted' }}">
                                <td>
                                    <div class="cell-primary">{{ $game->name }}</div>
                                    <div class="cell-secondary mono">{{ $game->slug }}</div>
                                    @if ($game->description)
                                        <div class="cell-secondary">{{ $game->description }}</div>
                                    @endif
                                </td>

                                <td class="cell-numeric">{{ $game->business_games_count }}</td>

                                <td>
                                    <x-ui.badge :variant="$game->status->badge()">
                                        {{ $game->status->label() }}
                                    </x-ui.badge>
                                </td>

                                <td class="cell-actions">
                                    <div class="btn-group">
                                        <x-ui.button variant="ghost" size="sm" type="button"
                                                     data-modal-open="rename-{{ $game->id }}">Rename</x-ui.button>

                                        @if ($game->isActive())
                                            <form method="POST" action="{{ route('admin.games.deactivate', $game) }}"
                                                  data-confirm="Hide &quot;{{ $game->name }}&quot; from new listings?"
                                                  data-confirm-detail="Venues already using it keep it — only new selections are prevented."
                                                  data-confirm-action="Deactivate"
                                                  data-confirm-tone="warning">
                                                @csrf
                                                <x-ui.button type="submit" variant="ghost" size="sm">Deactivate</x-ui.button>
                                            </form>
                                        @else
                                            <form method="POST" action="{{ route('admin.games.activate', $game) }}">
                                                @csrf
                                                <x-ui.button type="submit" variant="ghost" size="sm">Reactivate</x-ui.button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <aside class="aside-sticky">
            <x-ui.card title="Add a category">
                <form method="POST" action="{{ route('admin.games.store') }}" class="form">
                    @csrf

                    <x-ui.field label="Name" name="name" required hint="How customers will see it.">
                        <x-ui.input name="name" required placeholder="Table Tennis" />
                    </x-ui.field>

                    <x-ui.field label="Description" name="description">
                        <x-ui.textarea name="description" rows="2" placeholder="Table tennis tables." />
                    </x-ui.field>

                    <x-ui.button type="submit" variant="primary" block icon="plus">Add category</x-ui.button>
                </form>
            </x-ui.card>
        </aside>
    </div>

    @foreach ($games as $game)
        <x-ui.modal id="rename-{{ $game->id }}" title="Rename category" width="narrow">
            <form method="POST" action="{{ route('admin.games.update', $game) }}" class="form" id="rename-form-{{ $game->id }}">
                @csrf
                @method('put')

                <x-ui.field label="Name" name="name" required>
                    <x-ui.input name="name" :value="$game->name" required />
                </x-ui.field>

                <x-ui.field label="Description" name="description">
                    <x-ui.textarea name="description" :value="$game->description" rows="2" />
                </x-ui.field>

                <p class="field-hint">
                    {{-- Slugs live in shared search URLs, so renaming deliberately
                         leaves the slug alone rather than breaking those links. --}}
                    The URL slug stays <span class="mono">{{ $game->slug }}</span> so existing
                    search links keep working.
                </p>
            </form>

            <x-slot:footer>
                <x-ui.button variant="secondary" type="button" data-modal-close>Cancel</x-ui.button>
                <x-ui.button variant="primary" type="submit" form="rename-form-{{ $game->id }}">Save</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endforeach
</x-console-layout>
