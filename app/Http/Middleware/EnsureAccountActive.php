<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended account (FR-3.4) is logged out on its next request rather than
 * left holding a live session.
 *
 * Checking only at login would let an account suspended mid-session keep
 * operating until it happened to log out -- which is exactly when suspension
 * matters most, since suspension usually follows abuse in progress.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            $reason = $user->suspension_reason;

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', trim(
                'Your account has been suspended. '.($reason ? "Reason: {$reason}" : 'Please contact support.')
            ));
        }

        return $next($request);
    }
}
