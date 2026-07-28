<x-console-layout title="Add venue" context="owner">
    <x-ui.page-header
        title="Add a venue"
        description="Tell us about the venue. Our team reviews new listings before they go live."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('owner.businesses.index') }}" class="link-muted">Venues</a>
            <span class="breadcrumb-sep">/</span>
            <span>New venue</span>
        </x-slot:breadcrumb>
    </x-ui.page-header>

    <form method="POST" action="{{ route('owner.businesses.store') }}" enctype="multipart/form-data">
        @csrf

        @include('owner.businesses._form', ['business' => null])

        <div class="form-actions" style="margin-top: var(--space-6);">
            <x-ui.button type="submit" variant="primary">Save and choose categories</x-ui.button>
            <x-ui.button :href="route('owner.businesses.index')" variant="ghost">Cancel</x-ui.button>
        </div>
    </form>
</x-console-layout>
