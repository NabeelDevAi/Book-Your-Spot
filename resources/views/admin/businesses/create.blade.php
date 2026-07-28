<x-console-layout title="Add venue" context="admin">
    <x-ui.page-header
        title="Add a venue on behalf of an owner"
        description="For pilot venues onboarded in person or over the phone (FR-3.8)."
    >
        <x-slot:breadcrumb>
            <a href="{{ route('admin.businesses.index') }}" class="link-muted">Venues</a>
            <span class="breadcrumb-sep">/</span>
            <span>New venue</span>
        </x-slot:breadcrumb>
    </x-ui.page-header>

    <x-ui.alert variant="info" style="margin-bottom: var(--space-5);">
        Venues added here go live immediately — you're vouching for them, so they skip the
        review queue. The owner can then manage it themselves.
    </x-ui.alert>

    <form method="POST" action="{{ route('admin.businesses.store') }}" enctype="multipart/form-data">
        @csrf

        <x-ui.card title="Owner" style="margin-bottom: var(--space-6);">
            @if ($owners->isEmpty())
                <p class="text-muted">
                    No owner accounts yet. The owner needs to register first — then you can list
                    their venue for them.
                </p>
            @else
                <x-ui.field label="Which owner does this belong to?" name="owner_id" required>
                    <x-ui.select name="owner_id" required placeholder="Choose an owner">
                        @foreach ($owners as $owner)
                            <option value="{{ $owner->id }}" @selected((int) old('owner_id') === $owner->id)>
                                {{ $owner->name }} — {{ $owner->email }}
                            </option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            @endif
        </x-ui.card>

        @include('owner.businesses._form', ['business' => null])

        <div class="form-actions" style="margin-top: var(--space-6);">
            <x-ui.button type="submit" variant="primary" :disabled="$owners->isEmpty()">
                Create and publish
            </x-ui.button>
            <x-ui.button :href="route('admin.businesses.index')" variant="ghost">Cancel</x-ui.button>
        </div>
    </form>
</x-console-layout>
