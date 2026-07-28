<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * FR-3.4: view, search and moderate every account on the platform.
 */
class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): View
    {
        $users = User::query()
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = '%'.$request->string('search')->trim().'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->string('role')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->withCount('businesses')
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'roles' => UserRole::cases(),
            'statuses' => UserStatus::cases(),
        ]);
    }

    public function show(User $user): View
    {
        $user->loadCount(['businesses', 'reservations']);

        return view('admin.users.show', compact('user'));
    }

    public function suspend(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        // Suspending an admin -- including yourself -- would be an unrecoverable
        // lockout, since admin accounts cannot be self-registered.
        if ($user->isAdmin()) {
            return back()->with('error', 'Administrator accounts cannot be suspended from here.');
        }

        $user->forceFill([
            'status' => UserStatus::Suspended,
            'suspension_reason' => $validated['reason'],
            'suspended_at' => now(),
            'suspended_by' => Auth::id(),
        ])->save();

        $this->audit->log(AuditLogger::USER_SUSPENDED, $user, $validated['reason']);

        return back()->with('success', "{$user->name}'s account has been suspended.");
    }

    public function reinstate(User $user): RedirectResponse
    {
        $user->forceFill([
            'status' => UserStatus::Active,
            'suspension_reason' => null,
            'suspended_at' => null,
            'suspended_by' => null,
        ])->save();

        $this->audit->log(AuditLogger::USER_REINSTATED, $user);

        return back()->with('success', "{$user->name}'s account has been reinstated.");
    }
}
