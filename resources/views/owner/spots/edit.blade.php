<x-console-layout :title="$spot->name" context="owner">
    <x-ui.page-header :title="$spot->name" :description="$spot->businessGame->game->name.' at '.$business->name">
        <x-slot:breadcrumb>
            <a href="{{ route('owner.businesses.index') }}" class="link-muted">Venues</a>
            <span class="breadcrumb-sep">/</span>
            <a href="{{ route('owner.businesses.spots.index', $business) }}" class="link-muted">{{ $business->name }}</a>
            <span class="breadcrumb-sep">/</span>
            <span>{{ $spot->name }}</span>
        </x-slot:breadcrumb>

        <x-slot:actions>
            <x-ui.button
                :href="route('owner.businesses.spots.blocks.index', [$business, $spot])"
                variant="secondary" icon="calendar"
            >Blocked times</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    @if (! $spot->isActive())
        <x-ui.alert variant="warning" title="This spot is inactive" style="margin-bottom: var(--space-5);">
            Customers can't see or book it. Reactivate it from the spots list when it's back in service.
        </x-ui.alert>
    @endif

    @if ($futureBookings > 0)
        <x-ui.alert variant="info" style="margin-bottom: var(--space-5);">
            {{ $futureBookings }} upcoming {{ Str::plural('booking', $futureBookings) }} on this spot.
            Price changes apply to new bookings only — those keep the price they were made at.
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('owner.businesses.spots.update', [$business, $spot]) }}" enctype="multipart/form-data">
        @csrf
        @method('put')

        @include('owner.spots._form')

        <div class="form-actions" style="margin-top: var(--space-6);">
            <x-ui.button type="submit" variant="primary">Save changes</x-ui.button>
            <x-ui.button :href="route('owner.businesses.spots.index', $business)" variant="ghost">Back</x-ui.button>
        </div>
    </form>

    {{-- SRS 9.9: hard delete only with zero reservation history. --}}
    <x-ui.card title="Remove this spot" style="margin-top: var(--space-8); border-color: var(--color-danger-border);">
        @if ($spot->canBeHardDeleted())
            <p class="text-secondary" style="margin-bottom: var(--space-4);">
                This spot has never been booked, so it can be deleted permanently.
            </p>

            <form method="POST" action="{{ route('owner.businesses.spots.destroy', [$business, $spot]) }}"
                  data-confirm="Delete {{ $spot->name }}?"
                  data-confirm-detail="This can't be undone."
                  data-confirm-action="Delete spot">
                @csrf
                @method('delete')
                <x-ui.button type="submit" variant="danger-outline" icon="trash">Delete spot</x-ui.button>
            </form>
        @else
            <p class="text-secondary" style="margin-bottom: var(--space-4);">
                This spot has booking history, so it can't be deleted — those records belong to
                your customers too. Deactivate it instead: it disappears from search and stops
                taking new bookings, but nothing is lost.
            </p>

            @if ($spot->isActive())
                <form method="POST" action="{{ route('owner.businesses.spots.deactivate', [$business, $spot]) }}"
                      data-confirm="Take {{ $spot->name }} out of service?"
                      data-confirm-detail="Any upcoming bookings stay live and are flagged for you to resolve with the customer."
                      data-confirm-action="Deactivate"
                      data-confirm-tone="warning">
                    @csrf
                    <x-ui.button type="submit" variant="danger-outline">Deactivate spot</x-ui.button>
                </form>
            @endif
        @endif
    </x-ui.card>
</x-console-layout>
