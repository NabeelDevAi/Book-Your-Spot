<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Http\Request;

/**
 * The platform-wide holiday calendar (pricing amendment): every Spot bills
 * its weekend rate on a listed date, AND on the calendar day immediately
 * before it (Spot::isWeekendRateDay() / HolidayCalendar).
 *
 * One Admin-managed list rather than per-venue, agreed for V1 -- a public
 * holiday is the same date for every venue on the platform.
 */
class HolidayController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        return view('admin.holidays.index', [
            'holidays' => Holiday::query()->orderBy('date')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date', 'unique:holidays,date'],
            'name' => ['required', 'string', 'max:100'],
        ]);

        $holiday = Holiday::create($validated);

        $this->audit->log('holiday.added', $holiday, meta: ['date' => $holiday->date->toDateString(), 'name' => $holiday->name]);

        return back()->with('success', "\"{$holiday->name}\" added to the holiday calendar.");
    }

    public function destroy(Holiday $holiday): RedirectResponse
    {
        $this->audit->log('holiday.removed', $holiday, meta: ['date' => $holiday->date->toDateString(), 'name' => $holiday->name]);

        $holiday->delete();

        return back()->with('success', 'Holiday removed.');
    }
}
