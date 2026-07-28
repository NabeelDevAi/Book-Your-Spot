<x-guest-layout title="Confirm password">
    <div class="auth-header">
        <h1 class="auth-title">Confirm your password</h1>
        <p class="auth-subtitle">This is a secure area. Please re-enter your password to continue.</p>
    </div>

    <form method="POST" action="{{ route('password.confirm') }}" class="form">
        @csrf

        <x-ui.field label="Password" name="password" required>
            <x-ui.input name="password" type="password" required autofocus autocomplete="current-password" placeholder="••••••••" />
        </x-ui.field>

        <x-ui.button type="submit" variant="primary" block>Confirm</x-ui.button>
    </form>
</x-guest-layout>
