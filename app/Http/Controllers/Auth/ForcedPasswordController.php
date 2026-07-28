<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The screen a user is corralled onto after an Admin issues a temporary
 * password. See RequirePasswordChange middleware.
 */
class ForcedPasswordController extends Controller
{
    public function edit(): View
    {
        return view('auth.change-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        // Re-issuing the same temporary password would leave the account on a
        // credential a second person already knows, which is the entire problem
        // this screen exists to solve.
        if (Hash::check($validated['password'], $user->password)) {
            return back()->withErrors([
                'password' => 'Choose a password different from the temporary one you were given.',
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'must_change_password' => false,
        ])->save();

        // Invalidate any other sessions -- including whoever set the temporary
        // password, if they logged in as this user to test it.
        $request->session()->regenerate();

        return redirect()
            ->route($user->role->homeRoute())
            ->with('success', 'Your password has been updated.');
    }
}
