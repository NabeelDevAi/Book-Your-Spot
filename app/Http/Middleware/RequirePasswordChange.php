<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Forces a password change after an Admin has issued a temporary one.
 *
 * V1 sends no email, so a reset means an Admin sets a temporary password and
 * reads it out to the user. That password has been seen by a second person, so
 * the account is corralled onto the change screen until it is replaced.
 *
 * The escape hatches below matter: without them the user would be redirected
 * away from the very form that clears the flag, and would be unable to log out.
 */
class RequirePasswordChange
{
    /** Routes reachable while the flag is set. */
    private const ALLOWED = [
        'password.change',
        'password.change.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->routeIs(self::ALLOWED)) {
            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
