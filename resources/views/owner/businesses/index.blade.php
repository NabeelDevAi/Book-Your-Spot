<x-console-layout title="Venues" context="owner">
    <x-ui.page-header
        title="Your venues"
        description="One account can run several venues (FR-1.7)."
    >
        <x-slot:actions>
            <x-ui.button :href="route('owner.businesses.create')" variant="primary" icon="plus">
                Add venue
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if ($businesses->isEmpty())
        <x-ui.card>
            <x-ui.empty-state icon="building" title="No venues yet">
                Add your venue, choose what people can play there, and list the tables,
                courts or rooms they can book.

                <x-slot:action>
                    <x-ui.button :href="route('owner.businesses.create')" variant="primary" icon="plus">
                        Add your first venue
                    </x-ui.button>
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.card>
    @else
        <div class="stack-4">
            @foreach ($businesses as $business)
                <x-ui.card>
                    <div class="split split-start">
                        <div class="stack-2 flex-1">
                            <div class="cluster-2">
                                <h3 class="h4">{{ $business->name }}</h3>
                                <x-ui.badge :variant="$business->status->badge()">
                                    {{ $business->status->label() }}
                                </x-ui.badge>
                                @if ($business->duplicate_flagged)
                                    <x-ui.badge variant="warning">Flagged</x-ui.badge>
                                @endif
                            </div>

                            <p class="text-muted">
                                <x-ui.icon name="map-pin" :size="13" /> {{ $business->area }}, {{ $business->city }}
                                &nbsp;·&nbsp; {{ $business->business_games_count }}
                                {{ Str::plural('category', $business->business_games_count) }}
                                &nbsp;·&nbsp; {{ $business->spots_count }}
                                {{ Str::plural('spot', $business->spots_count) }}
                            </p>

                            <p class="text-sm text-muted">{{ $business->hours()->summary() }}</p>

                            @if ($business->status === \App\Enums\BusinessStatus::Rejected)
                                <p class="text-sm text-danger">
                                    Rejected: {{ $business->rejection_reason }}
                                    — edit and save to resubmit.
                                </p>
                            @elseif ($business->isSuspended())
                                <p class="text-sm text-danger">Suspended: {{ $business->suspension_reason }}</p>
                            @elseif ($business->isPendingReview())
                                <p class="text-sm text-warning">
                                    <strong>Awaiting approval</strong> — hidden from customers, but you can
                                    keep setting up categories, spots and pricing in the meantime.
                                </p>
                            @elseif ($business->spots_count === 0)
                                {{-- SRS 9.17: approved but unbookable is invisible in search,
                                     which is confusing unless we say so. --}}
                                <p class="text-sm text-warning">
                                    Approved, but hidden from search until you add at least one active spot.
                                </p>
                            @endif
                        </div>

                        <div class="cluster-2">
                            <x-ui.button :href="route('owner.businesses.spots.index', $business)" variant="secondary" size="sm" icon="grid">
                                Spots
                            </x-ui.button>
                            <x-ui.button :href="route('owner.businesses.games.edit', $business)" variant="secondary" size="sm" icon="layers">
                                Categories
                            </x-ui.button>
                            <x-ui.button :href="route('owner.businesses.edit', $business)" variant="secondary" size="sm" icon="edit">
                                Edit
                            </x-ui.button>
                        </div>
                    </div>
                </x-ui.card>
            @endforeach
        </div>
    @endif
</x-console-layout>
