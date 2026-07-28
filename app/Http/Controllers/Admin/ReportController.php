<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** FR-3.7 / SRS section 8 -- platform reporting. */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'days' => ['nullable', 'integer', 'min:7', 'max:180'],
        ]);

        $from = filled($validated['from'] ?? null) ? Carbon::parse($validated['from']) : null;
        $to = filled($validated['to'] ?? null) ? Carbon::parse($validated['to']) : null;
        $days = (int) ($validated['days'] ?? 30);

        return view('admin.reports.index', [
            'headline' => $this->reports->headline($from, $to),
            'businessesByStatus' => $this->reports->businessesByStatus(),
            'reservationsByStatus' => $this->reports->reservationsByStatus($from, $to),
            'overTime' => $this->reports->reservationsOverTime($days),
            'byGame' => $this->reports->bookingsByGame(),
            'byArea' => $this->reports->bookingsByArea(),
            'quality' => $this->reports->businessQuality(),
            'signups' => $this->reports->signupsOverTime($days),
            'days' => $days,
            'from' => $from,
            'to' => $to,
        ]);
    }
}
