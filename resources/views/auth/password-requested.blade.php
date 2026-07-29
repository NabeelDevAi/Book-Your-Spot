<x-guest-layout title="Request received">
    <div class="auth-header">
        <h1 class="auth-title">Request received</h1>
        <p class="auth-subtitle">
            {{-- Deliberately does not confirm whether the address is registered:
                 saying so would turn this page into an account-enumeration oracle. --}}
            If <strong>{{ $email ?? 'that address' }}</strong> matches an account, our team
            will contact you on the phone number registered to it.
        </p>
    </div>

    <x-ui.card>
        <div class="stack-3">
            <div class="cluster-2">
                <span class="auth-point-icon" >
                    <x-ui.icon name="phone" :size="16" />
                </span>
                <div>
                    <div class="font-semibold">We'll call to confirm it's you</div>
                    <p class="text-muted text-sm">Keep the phone on your account handy.</p>
                </div>
            </div>

            <div class="cluster-2">
                <span class="auth-point-icon" >
                    <x-ui.icon name="lock" :size="16" />
                </span>
                <div>
                    <div class="font-semibold">You'll get a temporary password</div>
                    <p class="text-muted text-sm">You'll be asked to change it the moment you log in.</p>
                </div>
            </div>
        </div>
    </x-ui.card>

    <p class="auth-footer">
        <a href="{{ route('login') }}" class="link">Back to log in</a>
    </p>
</x-guest-layout>
