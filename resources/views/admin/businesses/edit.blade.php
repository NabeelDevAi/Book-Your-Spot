<x-console-layout :title="'Edit '.$business->name" context="admin">
    <x-ui.page-header title="Override edit" :description="$business->name">
        <x-slot:breadcrumb>
            <a href="{{ route('admin.businesses.index') }}" class="link-muted">Venues</a>
            <span class="breadcrumb-sep">/</span>
            <a href="{{ route('admin.businesses.show', $business) }}" class="link-muted">{{ $business->name }}</a>
            <span class="breadcrumb-sep">/</span>
            <span>Edit</span>
        </x-slot:breadcrumb>
    </x-ui.page-header>

    {{-- SRS 9.20: this is exactly the scenario the audit log exists for, so the
         reason is mandatory and the before/after is recorded. --}}
    <x-ui.alert variant="warning" title="You're editing someone else's listing" style="margin-bottom: var(--space-5);">
        Say why. The change, your name and the reason are written to the audit log so any later
        dispute about who changed what can be settled from the record.
    </x-ui.alert>

    <form method="POST" action="{{ route('admin.businesses.update', $business) }}" enctype="multipart/form-data">
        @csrf
        @method('put')

        <x-ui.card title="Reason for this override" style="margin-bottom: var(--space-6); border-color: var(--color-warning-border);">
            <x-ui.field label="Why are you changing this?" name="override_reason" required>
                <x-ui.textarea name="override_reason" rows="2" required
                               placeholder="Owner reported the phone number was wrong; corrected over the phone." />
            </x-ui.field>
        </x-ui.card>

        @include('owner.businesses._form')

        <div class="form-actions" style="margin-top: var(--space-6);">
            <x-ui.button type="submit" variant="primary">Save and log the change</x-ui.button>
            <x-ui.button :href="route('admin.businesses.show', $business)" variant="ghost">Cancel</x-ui.button>
        </div>
    </form>
</x-console-layout>
