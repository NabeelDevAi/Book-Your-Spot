<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BusinessStatus;
use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Business;
use App\Models\PasswordResetRequest;
use App\Models\Reservation;
use App\Services\Admin\ReportService;
use Illuminate\View\View;

/**
 * FR-3.7 -- the admin landing page.
 *
 * Deliberately queue-first rather than metrics-first: an admin opens this to
 * find out what is waiting on them. The full analytics live under Reports.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(): View
    {
        return view('admin.dashboard', [
            'pendingBusinesses' => Business::where('status', BusinessStatus::PendingReview)->count(),
            'flaggedDuplicates' => Business::where('duplicate_flagged', true)->count(),
            'openPasswordRequests' => PasswordResetRequest::open()->count(),
            'suspendedBusinesses' => Business::where('status', BusinessStatus::Suspended)->count(),

            'headline' => $this->reports->headline(),
            'overTime' => $this->reports->reservationsOverTime(14),

            'awaitingReview' => Business::query()
                ->where('status', BusinessStatus::PendingReview)
                ->with('owner')
                ->withCount('spots')
                ->oldest()
                ->limit(5)
                ->get(),

            'recentActivity' => AuditLog::with('actor')->latest()->limit(8)->get(),

            'todaysReservations' => Reservation::query()
                ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::Pending])
                ->whereBetween('start_datetime', [now()->startOfDay(), now()->endOfDay()])
                ->count(),
        ]);
    }
}
