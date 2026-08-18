<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended account (FR-3.4) is logged out on its next request rather than
 * left holding a live session. The same applies to an Owner account that
 * isn't (or is no longer) approved -- LoginRequest already keeps a
 * pending/rejected account from ever starting a session, so this is really
 * only reachable if an Admin acts on someone mid-session.
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
            $message = match ($user->status) {
                UserStatus::PendingApproval => 'Your account is awaiting admin approval. '
                    .'We\'ll let you know once it\'s reviewed.',
                UserStatus::Rejected => trim('Your account registration wasn\'t approved. '
                    .($user->rejection_reason ? "Reason: {$user->rejection_reason}" : 'Please contact support.')),
                default => trim('Your account has been suspended. '
                    .($user->suspension_reason ? "Reason: {$user->suspension_reason}" : 'Please contact support.')),
            };

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', $message);
        }

        return $next($request);
    }
}
