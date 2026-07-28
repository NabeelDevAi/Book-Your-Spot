<x-app-layout title="Profile">
    <x-ui.page-header
        title="Profile"
        description="Manage your account details and password."
    />

    <div class="stack-6" style="max-width: 720px;">
        @include('profile.partials.update-profile-information-form')
        @include('profile.partials.update-password-form')
        @include('profile.partials.delete-user-form')
    </div>
</x-app-layout>
