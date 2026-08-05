<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * FR-1.1 / FR-1.2: two registration tracks behind one form.
     *
     * A Customer can book immediately. An Owner lands on their console and is
     * prompted to create a Business, which then enters `pending_review`.
     *
     * Note there is no verification gate: V1 delivers no email or SMS, so
     * FR-1.4 is waived. The `email_verified_at` column is still stamped so the
     * gate can be switched on the day a provider exists, without a backfill.
     */
    public function store(RegisterRequest $request): RedirectResponse
    {
        $role = UserRole::from($request->validated('role'));

        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'phone' => $request->validated('phone'),
            'password' => $request->validated('password'),
            'role' => $role,
        ]);

        $user->forceFill([
            'status' => UserStatus::Active,
            'email_verified_at' => now(),
        ])->save();

        event(new Registered($user));

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route($role->homeRoute())->with(
            'success',
            $role === UserRole::Owner
                ? 'Welcome to Venu365. Add your venue to get listed.'
                : 'Welcome to Venu365. Find a spot and send your first request.'
        );
    }
}
