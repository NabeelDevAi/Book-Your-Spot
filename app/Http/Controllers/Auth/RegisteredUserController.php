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
     * A Customer can book immediately. An Owner is gated behind Admin approval
     * (the amendment agreed with the product owner): the account is created as
     * `pending_approval` and cannot log in (LoginRequest) until an Admin
     * reviews it -- so it is deliberately NOT auto-logged-in here. A Business
     * still separately enters `pending_review` once the (now-approved) Owner
     * creates one; approving the account is not approving a venue.
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
            'status' => $role === UserRole::Owner ? UserStatus::PendingApproval : UserStatus::Active,
            'email_verified_at' => now(),
        ])->save();

        event(new Registered($user));

        if ($role === UserRole::Owner) {
            return redirect()->route('login')->with(
                'success',
                'Registration received. An admin will review your account shortly -- '
                .'you can log in once it\'s approved.'
            );
        }

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route($role->homeRoute())
            ->with('success', 'Welcome to Venu365. Find a spot and send your first request.');
    }
}
