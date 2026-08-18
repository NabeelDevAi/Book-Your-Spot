<?php

namespace App\Http\Requests\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // Reject a suspended/pending/rejected account here rather than letting
        // it log in and be bounced by middleware on the next request. Doing it
        // at the door means the user gets a real explanation instead of a
        // session that mysteriously dies, and no such session is ever created.
        $user = Auth::user();

        if (! $user->isActive()) {
            Auth::guard('web')->logout();
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => $this->inactiveMessage($user),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /** One explanation per reason an account can't log in right now. */
    private function inactiveMessage(User $user): string
    {
        return match ($user->status) {
            UserStatus::PendingApproval => 'Your account is awaiting admin approval. '
                .'We\'ll let you know once it\'s reviewed.',
            UserStatus::Rejected => trim('Your account registration wasn\'t approved. '
                .($user->rejection_reason ? "Reason: {$user->rejection_reason}" : 'Please contact support.')),
            default => trim('This account has been suspended. '
                .($user->suspension_reason ? "Reason: {$user->suspension_reason}" : 'Please contact support.')),
        };
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
