<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PasswordResetStatus;
use App\Http\Controllers\Controller;
use App\Models\PasswordResetRequest;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * The Admin half of the password-reset replacement (FR-1.5 amendment).
 *
 * The Admin verifies the person out of band -- a phone call to the number on
 * the account -- then issues a temporary password here and reads it to them.
 * The user is forced to change it at first login.
 */
class PasswordResetRequestController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('admin.password-requests.index', [
            'requests' => PasswordResetRequest::with('user', 'resolver')
                ->orderByRaw("FIELD(status, 'open') DESC")
                ->latest()
                ->paginate(25),
        ]);
    }

    public function issue(Request $request, PasswordResetRequest $passwordResetRequest): RedirectResponse
    {
        if (! $passwordResetRequest->isOpen()) {
            return back()->with('error', 'This request has already been handled.');
        }

        $validated = $request->validate([
            'temporary_password' => ['required', 'string', 'min:8', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $passwordResetRequest->loadMissing('user')->user;

        $user->forceFill([
            'password' => Hash::make($validated['temporary_password']),
            'must_change_password' => true,
        ])->save();

        $passwordResetRequest->forceFill([
            'status' => PasswordResetStatus::Resolved,
            'note' => $validated['note'] ?? null,
            'resolved_by' => Auth::id(),
            'resolved_at' => now(),
        ])->save();

        // The temporary password itself is deliberately never written to the
        // audit log -- the log records that a reset happened, not the credential.
        $this->audit->log(
            AuditLogger::PASSWORD_RESET_ISSUED,
            $user,
            $validated['note'] ?? null,
            ['request_id' => $passwordResetRequest->id],
        );

        return back()->with(
            'success',
            "Temporary password set for {$user->name}. Share it with them directly — "
            .'they will be asked to change it at next login.'
        );
    }

    public function dismiss(Request $request, PasswordResetRequest $passwordResetRequest): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $passwordResetRequest->forceFill([
            'status' => PasswordResetStatus::Dismissed,
            'note' => $validated['note'] ?? null,
            'resolved_by' => Auth::id(),
            'resolved_at' => now(),
        ])->save();

        $this->audit->log(
            AuditLogger::PASSWORD_RESET_DISMISSED,
            $passwordResetRequest,
            $validated['note'] ?? null,
        );

        return back()->with('success', 'Request dismissed.');
    }
}
