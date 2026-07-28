<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-1.6: role separation is enforced server-side on every route, regardless of
 * what the UI does or does not render.
 *
 * Usage: ->middleware('role:owner') or ->middleware('role:owner,admin')
 *
 * A wrong-role request gets 403 rather than a redirect. Redirecting would leak
 * which routes exist for other roles, and a User who somehow reaches an Owner
 * URL has either mistyped it or is probing -- neither deserves a helpful bounce.
 */
class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        $allowed = array_map(
            fn (string $role) => UserRole::from($role),
            $roles
        );

        if (! in_array($user->role, $allowed, true)) {
            abort(403, 'This area is not available for your account type.');
        }

        return $next($request);
    }
}
