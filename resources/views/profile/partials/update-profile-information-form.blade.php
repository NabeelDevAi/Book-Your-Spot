<x-ui.card
    title="Account information"
    subtitle="Update your name and email address."
>
    @if (session('status') === 'profile-updated')
        <x-ui.alert variant="success" :auto-dismiss="5000" style="margin-bottom: var(--space-4);">
            Profile updated.
        </x-ui.alert>
    @endif

    <form method="POST" action="{{ route('profile.update') }}" class="form">
        @csrf
        @method('patch')

        <div class="form-row">
            <x-ui.field label="Full name" name="name" required>
                <x-ui.input name="name" :value="$user->name" required autocomplete="name" />
            </x-ui.field>

            <x-ui.field label="Email address" name="email" required>
                <x-ui.input name="email" type="email" :value="$user->email" required autocomplete="username" />
            </x-ui.field>
        </div>

        <div class="form-row">
            <x-ui.field
                label="Phone number"
                name="phone"
                required
                hint="Used to reach you about bookings."
            >
                <x-ui.input name="phone" type="tel" :value="$user->phone" required autocomplete="tel" />
            </x-ui.field>

            <x-ui.field label="Account type">
                {{-- One account holds one role for its lifetime (SRS 9.14).
                     Switching would orphan either venues or booking history. --}}
                <x-ui.input :value="$user->role->label()" disabled />
                <p class="field-hint">Account type can't be changed after registration.</p>
            </x-ui.field>
        </div>

        <div class="form-actions">
            <x-ui.button type="submit" variant="primary">Save changes</x-ui.button>
        </div>
    </form>
</x-ui.card>
