<x-ui.card
    title="Password"
    subtitle="Use a long, unique password to keep your account secure."
>
    @if (session('status') === 'password-updated')
        <x-ui.alert variant="success" :auto-dismiss="5000" style="margin-bottom: var(--space-4);">
            Password updated.
        </x-ui.alert>
    @endif

    {{-- This form validates into its own error bag so a failure here doesn't
         light up the account-information form above it. --}}
    <form method="POST" action="{{ route('password.update') }}" class="form">
        @csrf
        @method('put')

        <x-ui.field label="Current password" name="current_password" bag="updatePassword" required>
            <x-ui.input name="current_password" type="password" bag="updatePassword" required autocomplete="current-password" />
        </x-ui.field>

        <div class="form-row">
            <x-ui.field label="New password" name="password" bag="updatePassword" required hint="At least 8 characters.">
                <x-ui.input name="password" type="password" bag="updatePassword" required autocomplete="new-password" />
            </x-ui.field>

            <x-ui.field label="Confirm new password" name="password_confirmation" bag="updatePassword" required>
                <x-ui.input name="password_confirmation" type="password" bag="updatePassword" required autocomplete="new-password" />
            </x-ui.field>
        </div>

        <div class="form-actions">
            <x-ui.button type="submit" variant="primary">Update password</x-ui.button>
        </div>
    </form>
</x-ui.card>
