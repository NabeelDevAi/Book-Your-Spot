{{--
    V1 sends no email, so there is no reset link to click. Submitting this form
    files a request for an Admin, who verifies the person by phone and issues a
    temporary password (FR-1.5 amendment).
--}}
<x-guest-layout title="Password help">
    <div class="auth-header">
        <h1 class="auth-title">Forgotten your password?</h1>
        <p class="auth-subtitle">
            Tell us the email on your account and our team will get you back in.
        </p>
    </div>

    <x-ui.alert variant="info" style="margin-bottom: var(--space-5);">
        We don't send reset emails yet. Instead, our team verifies you by phone and
        sets a temporary password you'll change when you log in.
    </x-ui.alert>

    <form method="POST" action="{{ route('password.email') }}" class="form">
        @csrf

        <x-ui.field label="Email address" name="email" required>
            <x-ui.input name="email" type="email" required autofocus placeholder="you@example.com" />
        </x-ui.field>

        <x-ui.button type="submit" variant="primary" block>Request help</x-ui.button>
    </form>

    <p class="auth-footer">
        <a href="{{ route('login') }}" class="link">Back to log in</a>
    </p>
</x-guest-layout>
