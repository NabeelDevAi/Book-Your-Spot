<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * FR-3.5 -- every reservation on the platform, with the filtering the SRS asks
 * for: business, user, date, status and area.
 */
class ReservationController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'business' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'area' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $reservations = Reservation::query()
            ->with(['user', 'business', 'spot.businessGame.game'])
            ->when(filled($filters['business'] ?? null), fn (Builder $q) => $q->where('business_id', $filters['business']))
            ->when(filled($filters['status'] ?? null), fn (Builder $q) => $q->where('status', $filters['status']))
            ->when(filled($filters['area'] ?? null), fn (Builder $q) => $q->whereHas(
                'business', fn (Builder $b) => $b->where('area', $filters['area'])
            ))
            ->when(filled($filters['from'] ?? null), fn (Builder $q) => $q->where('start_datetime', '>=', Carbon::parse($filters['from'])->startOfDay()))
            ->when(filled($filters['to'] ?? null), fn (Builder $q) => $q->where('start_datetime', '<=', Carbon::parse($filters['to'])->endOfDay()))
            ->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters) {
                $term = '%'.trim((string) $filters['search']).'%';
                // Reference first: an admin chasing a support call almost always
                // has the code the customer read out.
                $q->where(fn (Builder $inner) => $inner
                    ->where('reference', 'like', $term)
                    ->orWhereHas('user', fn (Builder $u) => $u
                        ->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term)));
            })
            ->latest('start_datetime')
            ->paginate(30)
            ->withQueryString();

        return view('admin.reservations.index', [
            'reservations' => $reservations,
            'statuses' => ReservationStatus::cases(),
            'businesses' => Business::orderBy('name')->get(['id', 'name']),
            'areas' => Business::select('area')->distinct()->orderBy('area')->pluck('area'),
            'filters' => $filters,
        ]);
    }

    public function show(Reservation $reservation): View
    {
        return view('admin.reservations.show', [
            'reservation' => $reservation->load([
                'user', 'business.owner', 'spot.businessGame.game',
                'responder', 'canceller', 'noShowFlagger', 'conflicts.resolver',
            ]),
            'auditTrail' => \App\Models\AuditLog::forTarget(Reservation::class, $reservation->id)
                ->with('actor')
                ->latest()
                ->get(),
        ]);
    }
}
