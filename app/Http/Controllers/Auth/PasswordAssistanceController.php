<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Replaces Breeze's emailed reset link (FR-1.5 amendment).
 *
 * V1 has no mail delivery, so a link would go nowhere. Instead the user files a
 * request, an Admin verifies them out of band and issues a temporary password,
 * and the user is forced to change it at first login.
 */
class PasswordAssistanceController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($user) {
            // One open request per account. Repeated submissions refresh the
            // existing row rather than flooding the Admin queue with duplicates.
            $existing = $user->passwordResetRequests()->open()->first();

            if ($existing) {
                $existing->touch();
            } else {
                $created = PasswordResetRequest::create([
                    'user_id' => $user->id,
                    'submitted_email' => $validated['email'],
                    'ip_address' => $request->ip(),
                ]);

                $this->audit->log(
                    AuditLogger::PASSWORD_RESET_REQUESTED,
                    $created,
                    meta: ['email' => $validated['email']],
                    actor: $user,
                );
            }
        }

        // The same response either way. Revealing whether an address is
        // registered turns this form into an account-enumeration oracle.
        return redirect()
            ->route('password.requested')
            ->with('submitted_email', $validated['email']);
    }

    public function requested(Request $request): View
    {
        return view('auth.password-requested', [
            'email' => $request->session()->get('submitted_email'),
        ]);
    }
}
