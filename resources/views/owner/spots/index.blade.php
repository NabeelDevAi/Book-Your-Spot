<x-console-layout title="Spots" context="owner">
    <x-ui.page-header
        title="Bookable spots"
        description="Each table, court or room customers can reserve at {{ $business->name }}."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('owner.businesses.index') }}" class="link-muted">Venues</a>
            <span class="breadcrumb-sep">/</span>
            <a href="{{ route('owner.businesses.edit', $business) }}" class="link-muted">{{ $business->name }}</a>
            <span class="breadcrumb-sep">/</span>
            <span>Spots</span>
        </x-slot:breadcrumb>

        <x-slot:actions>
            @if ($businessGames->isNotEmpty())
                <x-ui.button :href="route('owner.businesses.spots.create', $business)" variant="primary" icon="plus">
                    Add spot
                </x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <x-owner.venue-status-banner :business="$business" />

    @if ($businessGames->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="layers" title="Choose a category first">
                Spots belong to a category — a snooker table, a futsal court, a PS5 room.
                Pick what your venue offers and then add the individual spots.

                <x-slot:action>
                    <x-ui.button :href="route('owner.businesses.games.edit', $business)" variant="primary">
                        Choose categories
                    </x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="stack-6">
            @foreach ($businessGames as $businessGame)
                <x-ui.card :title="$businessGame->game->name" flush>
                    <x-slot:actions>
                        <x-ui.button
                            :href="route('owner.businesses.spots.create', $business).'?game='.$businessGame->id"
                            variant="secondary"
                            size="sm"
                            icon="plus"
                        >Add {{ $businessGame->game->name }} spot</x-ui.button>
                    </x-slot:actions>

                    @if ($businessGame->spots->isEmpty())
                        <x-ui.empty-state icon="grid" title="No spots in this category yet">
                            Add the individual tables, courts or rooms customers can book.
                        </x-ui.empty-state>
                    @else
                        <div class="table-wrap">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Spot</th>
                                        <th>Rate</th>
                                        <th>Minimum booking</th>
                                        <th>Hours</th>
                                        <th>Status</th>
                                        <th class="cell-actions">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($businessGame->spots as $spot)
                                        <tr class="{{ $spot->isActive() ? '' : 'is-muted' }}">
                                            <td>
                                                <div class="cell-primary">{{ $spot->name }}</div>
                                                @if ($spot->reservations_count > 0)
                                                    <div class="cell-secondary">
                                                        {{ $spot->reservations_count }}
                                                        {{ Str::plural('booking', $spot->reservations_count) }} on record
                                                    </div>
                                                @endif
                                            </td>
                                            <td class="mono">{{ $spot->rateLabel() }}</td>
                                            <td class="cell-secondary">
                                                {{ \App\Support\Money::duration($spot->min_duration_minutes) }}
                                            </td>
                                            <td class="cell-secondary">
                                                @if ($spot->hasHoursOverride())
                                                    <x-ui.badge variant="info">Custom</x-ui.badge>
                                                @else
                                                    Venue hours
                                                @endif
                                            </td>
                                            <td>
                                                <x-ui.badge :variant="$spot->status->badge()">
                                                    {{ $spot->status->label() }}
                                                </x-ui.badge>
                                            </td>
                                            <td class="cell-actions">
                                                <div class="btn-group">
                                                    @if ($spot->isActive() && $business->acceptsBookings())
                                                        <x-ui.button
                                                            :href="route('owner.businesses.spots.reservations.create', [$business, $spot])"
                                                            variant="secondary" size="sm" icon="plus"
                                                        >New booking</x-ui.button>
                                                    @elseif ($spot->isActive())
                                                        <span class="text-xs text-muted" title="Available once the venue is approved">
                                                            New booking — pending approval
                                                        </span>
                                                    @endif

                                                    <x-ui.button
                                                        :href="route('owner.businesses.spots.blocks.index', [$business, $spot])"
                                                        variant="ghost" size="sm" icon="calendar"
                                                    >Blocks</x-ui.button>

                                                    <x-ui.button
                                                        :href="route('owner.businesses.spots.edit', [$business, $spot])"
                                                        variant="secondary" size="sm"
                                                    >Edit</x-ui.button>

                                                    @if ($spot->isActive())
                                                        {{-- SRS 9.9: deactivating with live bookings raises
                                                             conflicts rather than cancelling them, so the
                                                             confirmation says exactly that. --}}
                                                        <form method="POST"
                                                              action="{{ route('owner.businesses.spots.deactivate', [$business, $spot]) }}"
                                                              data-confirm="Take {{ $spot->name }} out of service?"
                                                              data-confirm-detail="Customers won't see it. Any upcoming bookings stay live and are flagged for you to resolve."
                                                              data-confirm-action="Deactivate"
                                                              data-confirm-tone="warning">
                                                            @csrf
                                                            <x-ui.button type="submit" variant="ghost" size="sm">
                                                                Deactivate
                                                            </x-ui.button>
                                                        </form>
                                                    @else
                                                        <form method="POST"
                                                              action="{{ route('owner.businesses.spots.activate', [$business, $spot]) }}">
                                                            @csrf
                                                            <x-ui.button type="submit" variant="ghost" size="sm">
                                                                Reactivate
                                                            </x-ui.button>
                                                        </form>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-ui.card>
            @endforeach
        </div>
    @endif
</x-console-layout>
