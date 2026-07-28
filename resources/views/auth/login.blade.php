<x-guest-layout title="Log in">
    <div class="auth-header">
        <h1 class="auth-title">Welcome back</h1>
        <p class="auth-subtitle">Log in to book a spot or manage your venue.</p>
    </div>

    <form method="POST" action="{{ route('login') }}" class="form">
        @csrf

        <x-ui.field label="Email address" name="email" required>
            <x-ui.input
                name="email"
                type="email"
                required
                autofocus
                autocomplete="username"
                placeholder="you@example.com"
            />
        </x-ui.field>

        <x-ui.field label="Password" name="password" required>
            <x-ui.input
                name="password"
                type="password"
                required
                autocomplete="current-password"
                placeholder="••••••••"
            />
        </x-ui.field>

        <div class="split">
            <label class="checkbox">
                <input type="checkbox" name="remember" value="1">
                <span>Keep me logged in</span>
            </label>

            @if (Route::has('password.request'))
                <a href="{{ route('password.request') }}" class="link text-sm">Forgot password?</a>
            @endif
        </div>

        <x-ui.button type="submit" variant="primary" block>Log in</x-ui.button>
    </form>

    <p class="auth-footer">
        Don't have an account? <a href="{{ route('register') }}" class="link">Create one</a>
    </p>
</x-guest-layout>
