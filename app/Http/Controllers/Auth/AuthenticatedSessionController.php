<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): View
    {
        // SRS 9.13: a guest who hits "Book" is sent here with where they came
        // from, and must land back on that exact page afterwards. Being dumped
        // on a generic dashboard after signing in mid-booking is how people
        // abandon a booking.
        //
        // Only same-origin paths are accepted -- an attacker-supplied absolute
        // URL would turn the login page into an open redirect.
        $redirect = $request->query('redirect');

        if (is_string($redirect) && $this->isLocalUrl($redirect)) {
            $request->session()->put('url.intended', $redirect);
        }

        return view('auth.login');
    }

    private function isLocalUrl(string $url): bool
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        return str_starts_with($url, config('app.url').'/')
            || str_starts_with($url, url('/').'/');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();

        // An Admin-issued temporary password has been spoken aloud to the user,
        // so it must be replaced before the account can do anything else.
        if ($user->must_change_password) {
            return redirect()->route('password.change');
        }

        // Each role has its own home. `intended()` still wins when the user was
        // bounced here from a specific page -- that is what makes the guest
        // "Book now" -> login -> back to the booking flow work (SRS 9.13).
        return redirect()->intended(route($user->role->homeRoute()));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
