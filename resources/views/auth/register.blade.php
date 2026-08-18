<x-guest-layout title="Create account" :wide="true">
    <div class="auth-header">
        <h1 class="auth-title">Create your account</h1>
        <p class="auth-subtitle">One account, one purpose — pick the one that fits you.</p>
    </div>

    <form method="POST" action="{{ route('register') }}" class="form">
        @csrf

        {{-- FR-1.1 / FR-1.2: the two registration tracks. An account is either a
             customer or an owner and cannot be both (SRS 9.14), so this is the
             first thing we ask rather than something buried in settings. --}}
        <fieldset>
            <legend class="field-label" style="margin-bottom: var(--space-2);">
                I want to<span class="required" aria-hidden="true">*</span>
            </legend>

            <div class="role-choice">
                <label class="role-option">
                    <input type="radio" name="role" value="user" @checked(old('role', 'user') === 'user')>
                    <span class="role-option-icon"><x-ui.icon name="search" :size="18" /></span>
                    <span class="role-option-title">Book a spot</span>
                    <span class="role-option-text">
                        Find snooker tables, courts and gaming rooms, and reserve them.
                    </span>
                </label>

                <label class="role-option">
                    <input type="radio" name="role" value="owner" @checked(old('role') === 'owner')>
                    <span class="role-option-icon"><x-ui.icon name="building" :size="18" /></span>
                    <span class="role-option-title">List my venue</span>
                    <span class="role-option-text">
                        Publish your tables, courts or rooms and take bookings.
                        An admin reviews and approves every new Owner account first.
                    </span>
                </label>
            </div>

            @error('role')
                <p class="field-error" style="margin-top: var(--space-2);">{{ $message }}</p>
            @enderror
        </fieldset>

        <x-ui.field label="Full name" name="name" required>
            <x-ui.input name="name" required autofocus autocomplete="name" placeholder="Ali Raza" />
        </x-ui.field>

        <div class="form-row">
            <x-ui.field label="Email address" name="email" required>
                <x-ui.input name="email" type="email" required autocomplete="username" placeholder="you@example.com" />
            </x-ui.field>

            <x-ui.field
                label="Phone number"
                name="phone"
                required
                hint="Venues use this to reach you about a booking."
            >
                <x-ui.input name="phone" type="tel" required autocomplete="tel" placeholder="+92 300 1234567" />
            </x-ui.field>
        </div>

        <div class="form-row">
            <x-ui.field label="Password" name="password" required hint="At least 8 characters.">
                <x-ui.input name="password" type="password" required autocomplete="new-password" placeholder="••••••••" />
            </x-ui.field>

            <x-ui.field label="Confirm password" name="password_confirmation" required>
                <x-ui.input name="password_confirmation" type="password" required autocomplete="new-password" placeholder="••••••••" />
            </x-ui.field>
        </div>

        <x-ui.button type="submit" variant="primary" block>Create account</x-ui.button>
    </form>

    <p class="auth-footer">
        Already have an account? <a href="{{ route('login') }}" class="link">Log in</a>
    </p>
</x-guest-layout>
