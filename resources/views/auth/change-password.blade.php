{{--
    Shown when must_change_password is set. RequirePasswordChange middleware
    keeps the user here until they replace the Admin-issued temporary password.
--}}
<x-guest-layout title="Set a new password">
    <div class="auth-header">
        <h1 class="auth-title">Choose a new password</h1>
        <p class="auth-subtitle">
            Your current password was issued by our team, so someone else has seen it.
            Pick a new one to carry on.
        </p>
    </div>

    <form method="POST" action="{{ route('password.change.update') }}" class="form">
        @csrf
        @method('put')

        <x-ui.field label="Temporary password" name="current_password" required>
            <x-ui.input name="current_password" type="password" required autofocus autocomplete="current-password" />
        </x-ui.field>

        <x-ui.field label="New password" name="password" required hint="At least 8 characters.">
            <x-ui.input name="password" type="password" required autocomplete="new-password" />
        </x-ui.field>

        <x-ui.field label="Confirm new password" name="password_confirmation" required>
            <x-ui.input name="password_confirmation" type="password" required autocomplete="new-password" />
        </x-ui.field>

        <x-ui.button type="submit" variant="primary" block>Save and continue</x-ui.button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="auth-footer">
        @csrf
        <button type="submit" class="link-muted">Log out instead</button>
    </form>
</x-guest-layout>
