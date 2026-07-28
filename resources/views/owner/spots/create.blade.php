<x-console-layout title="Add spot" context="owner">
    <x-ui.page-header title="Add a spot" description="A single table, court or room customers can book.">
        <x-slot:breadcrumb>
            <a href="{{ route('owner.businesses.index') }}" class="link-muted">Venues</a>
            <span class="breadcrumb-sep">/</span>
            <a href="{{ route('owner.businesses.spots.index', $business) }}" class="link-muted">{{ $business->name }}</a>
            <span class="breadcrumb-sep">/</span>
            <span>New spot</span>
        </x-slot:breadcrumb>
    </x-ui.page-header>

    <form method="POST" action="{{ route('owner.businesses.spots.store', $business) }}" enctype="multipart/form-data">
        @csrf

        @include('owner.spots._form', ['spot' => null])

        <div class="form-actions" style="margin-top: var(--space-6);">
            <x-ui.button type="submit" variant="primary">Add spot</x-ui.button>
            <x-ui.button :href="route('owner.businesses.spots.index', $business)" variant="ghost">Cancel</x-ui.button>
        </div>
    </form>
</x-console-layout>
