<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     *
     * Refused once the account has a wallet ledger. A ledger row is a financial
     * record: it is the evidence behind a customer's payment and an Owner's
     * earnings, and it has to outlive the login it happens to be attached to.
     * The database enforces this too -- wallet_transactions restricts deletion
     * of its wallet -- but a foreign-key error is a 500, and someone deleting
     * their account deserves an explanation instead.
     *
     * An account that has never transacted still deletes cleanly; its empty
     * wallet goes with it.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $user->loadMissing('wallet');
        $wallet = $user->wallet;

        if ($wallet !== null) {
            if ($wallet->transactions()->exists() || $wallet->totalOwnedMinor() > 0 || $wallet->held_minor > 0) {
                return Redirect::route('profile.edit')->with(
                    'error',
                    'This account has wallet activity and cannot be deleted here. '
                    .'Please contact support so any remaining balance can be settled first.',
                );
            }

            $wallet->delete();
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
