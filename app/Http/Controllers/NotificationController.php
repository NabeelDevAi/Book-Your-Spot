<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The in-app notification centre.
 *
 * V1 delivers nothing by email or SMS (the FR-5.1 amendment), so this is the
 * only place a user ever learns that a request arrived, was confirmed, was
 * declined or expired. That makes it load-bearing rather than decorative --
 * every notification has to be reachable and readable here.
 */
class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $notifications = $request->user()
            ->notifications()
            ->paginate(30);

        return view('notifications.index', [
            'notifications' => $notifications,
            'unreadCount' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /** Polled by the bell so a new request appears without a page refresh. */
    public function unread(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->unreadNotifications()
            ->take(8)
            ->get();

        return response()->json([
            'count' => $request->user()->unreadNotifications()->count(),
            'items' => $notifications->map(fn ($notification) => [
                'id' => $notification->id,
                'title' => $notification->data['title'] ?? 'Notification',
                'body' => $notification->data['body'] ?? '',
                'icon' => $notification->data['icon'] ?? 'bell',
                'tone' => $notification->data['tone'] ?? 'info',
                'url' => $this->urlFor($notification->data, $request),
                'ago' => $notification->created_at->diffForHumans(short: true),
            ])->values(),
        ]);
    }

    /**
     * Mark one as read and follow it to whatever it is about.
     *
     * Reading and navigating are one action deliberately: a notification you
     * clicked through is one you have dealt with.
     */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $record = $request->user()->notifications()->findOrFail($notification);

        $record->markAsRead();

        return redirect($this->urlFor($record->data, $request) ?? route('notifications.index'));
    }

    public function readAll(Request $request): RedirectResponse
    {
        // One UPDATE rather than fetching every unread row to mark each in turn.
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }

    /**
     * Where a notification points, which depends on who is reading it: an
     * owner needs the approval queue, a customer needs their booking.
     */
    private function urlFor(array $data, Request $request): ?string
    {
        $reference = $data['reference'] ?? null;

        if (! $reference) {
            return null;
        }

        if ($request->user()->isOwner()) {
            return route('owner.reservations.show', $reference);
        }

        return route('bookings.show', $reference);
    }
}
