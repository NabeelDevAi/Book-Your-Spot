<x-console-layout :title="$business->name" context="owner">
    <x-ui.page-header :title="$business->name" description="Edit this venue's profile.">
        <x-slot:breadcrumb>
            <a href="{{ route('owner.businesses.index') }}" class="link-muted">Venues</a>
            <span class="breadcrumb-sep">/</span>
            <span>{{ $business->name }}</span>
        </x-slot:breadcrumb>

        <x-slot:actions>
            <x-ui.button :href="route('owner.businesses.games.edit', $business)" variant="secondary" icon="layers">
                Categories
            </x-ui.button>
            <x-ui.button :href="route('owner.businesses.spots.index', $business)" variant="secondary" icon="grid">
                Spots
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-owner.venue-status-banner :business="$business" />

    <form method="POST" action="{{ route('owner.businesses.update', $business) }}" enctype="multipart/form-data">
        @csrf
        @method('put')

        @include('owner.businesses._form')

        <div class="form-actions is-split" style="margin-top: var(--space-6);">
            <div class="cluster-2">
                <x-ui.button type="submit" variant="primary">Save changes</x-ui.button>
                <x-ui.button :href="route('owner.businesses.index')" variant="ghost">Back</x-ui.button>
            </div>
        </div>
    </form>

    {{-- SRS 9.9 at the venue level: deletion only while there is no history. --}}
    <x-ui.card title="Delete venue" style="margin-top: var(--space-8); border-color: var(--color-danger-border);">
        @if ($business->reservations()->exists())
            <p class="text-secondary">
                This venue has booking history, so it can't be deleted — those records are kept
                for you and your customers. Contact support if you need it removed from search.
            </p>
        @else
            <p class="text-secondary" style="margin-bottom: var(--space-4);">
                This venue has no bookings yet, so it can be deleted permanently.
            </p>

            <form method="POST" action="{{ route('owner.businesses.destroy', $business) }}"
                  data-confirm="Delete {{ $business->name }}?"
                  data-confirm-detail="This removes the venue and all its spots. It can't be undone."
                  data-confirm-action="Delete venue">
                @csrf
                @method('delete')
                <x-ui.button type="submit" variant="danger-outline" icon="trash">Delete this venue</x-ui.button>
            </form>
        @endif
    </x-ui.card>
</x-console-layout>
