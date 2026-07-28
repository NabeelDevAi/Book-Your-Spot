<?php

namespace App\Services\Admin;

use App\Enums\BusinessStatus;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SRS section 8 -- platform-wide reporting (FR-3.7).
 *
 * Every figure is computed on demand. At pilot scale that is a handful of
 * indexed aggregate queries; a nightly rollup table would be premature here and
 * would introduce a whole class of "the dashboard disagrees with the data" bugs
 * for no benefit.
 */
class ReportService
{
    /** @return array<string, int> */
    public function businessesByStatus(): array
    {
        $counts = Business::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $result = [];

        foreach (BusinessStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }

    /** @return array<string, int> */
    public function reservationsByStatus(?Carbon $from = null, ?Carbon $to = null): array
    {
        $counts = $this->scopedReservations($from, $to)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $result = [];

        foreach (ReservationStatus::cases() as $status) {
            $result[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $result;
    }

    /**
     * Daily reservation counts for a trend line.
     *
     * Gaps are filled with zeroes: a chart that silently skips quiet days
     * compresses the x-axis and makes a flat week look like steady growth.
     *
     * @return Collection<int, array{date: Carbon, total: int}>
     */
    public function reservationsOverTime(int $days = 30): Collection
    {
        $from = Carbon::today()->subDays($days - 1);

        $counts = Reservation::query()
            ->where('requested_at', '>=', $from)
            ->selectRaw('DATE(requested_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        return collect(range(0, $days - 1))->map(function (int $offset) use ($from, $counts) {
            $date = $from->copy()->addDays($offset);

            return [
                'date' => $date,
                'total' => (int) ($counts[$date->toDateString()] ?? 0),
            ];
        });
    }

    /** Which categories actually get booked (SRS section 8). */
    public function bookingsByGame(int $limit = 10): Collection
    {
        return DB::table('reservations')
            ->join('spots', 'spots.id', '=', 'reservations.spot_id')
            ->join('business_games', 'business_games.id', '=', 'spots.business_game_id')
            ->join('games', 'games.id', '=', 'business_games.game_id')
            ->selectRaw('games.name as label, COUNT(*) as total')
            ->groupBy('games.name')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['label' => $row->label, 'total' => (int) $row->total]);
    }

    public function bookingsByArea(int $limit = 10): Collection
    {
        return DB::table('reservations')
            ->join('businesses', 'businesses.id', '=', 'reservations.business_id')
            ->selectRaw('businesses.area as label, COUNT(*) as total')
            ->groupBy('businesses.area')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => ['label' => $row->label, 'total' => (int) $row->total]);
    }

    /**
     * Venue quality signals (SRS section 8, and the early fraud detection the
     * SRS asks for).
     *
     * Rates are only shown once a venue has enough bookings to mean anything --
     * one rejection out of one request is 100% and tells you nothing, but it
     * would sort straight to the top of a "worst offenders" list.
     */
    public function businessQuality(int $limit = 15, int $minimumVolume = 5): Collection
    {
        $rows = DB::table('reservations')
            ->join('businesses', 'businesses.id', '=', 'reservations.business_id')
            ->selectRaw('
                businesses.id,
                businesses.name,
                businesses.area,
                COUNT(*) as total,
                SUM(CASE WHEN reservations.status = ? THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN reservations.status = ? THEN 1 ELSE 0 END) as no_shows,
                SUM(CASE WHEN reservations.status = ? THEN 1 ELSE 0 END) as expired,
                SUM(CASE WHEN reservations.status = ? THEN 1 ELSE 0 END) as completed
            ', [
                ReservationStatus::Rejected->value,
                ReservationStatus::NoShow->value,
                ReservationStatus::Expired->value,
                ReservationStatus::Completed->value,
            ])
            ->groupBy('businesses.id', 'businesses.name', 'businesses.area')
            ->havingRaw('COUNT(*) >= ?', [$minimumVolume])
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'id' => $row->id,
            'name' => $row->name,
            'area' => $row->area,
            'total' => (int) $row->total,
            'rejected' => (int) $row->rejected,
            'no_shows' => (int) $row->no_shows,
            // Letting requests expire is a distinct failure from declining them:
            // the owner simply never answered, and the customer waited for
            // nothing. Worth surfacing separately.
            'expired' => (int) $row->expired,
            'completed' => (int) $row->completed,
            'rejection_rate' => round(($row->rejected / $row->total) * 100),
            'no_show_rate' => round(($row->no_shows / $row->total) * 100),
            'unanswered_rate' => round(($row->expired / $row->total) * 100),
        ])->sortByDesc(
            fn (array $row) => $row['rejection_rate'] + $row['unanswered_rate']
        )->values();
    }

    /** @return Collection<int, array{date: Carbon, users: int, owners: int}> */
    public function signupsOverTime(int $days = 30): Collection
    {
        $from = Carbon::today()->subDays($days - 1);

        $counts = User::query()
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day, role, COUNT(*) as total')
            ->groupBy('day', 'role')
            ->get()
            ->groupBy('day');

        return collect(range(0, $days - 1))->map(function (int $offset) use ($from, $counts) {
            $date = $from->copy()->addDays($offset);
            $forDay = $counts[$date->toDateString()] ?? collect();

            return [
                'date' => $date,
                'users' => (int) ($forDay->firstWhere('role', UserRole::User->value)->total ?? 0),
                'owners' => (int) ($forDay->firstWhere('role', UserRole::Owner->value)->total ?? 0),
            ];
        });
    }

    /** @return array<string, int|float> */
    public function headline(?Carbon $from = null, ?Carbon $to = null): array
    {
        $reservations = $this->scopedReservations($from, $to);
        $total = (clone $reservations)->count();
        $confirmed = (clone $reservations)->whereIn('status', [
            ReservationStatus::Confirmed, ReservationStatus::Completed, ReservationStatus::NoShow,
        ])->count();

        return [
            'total_reservations' => $total,
            'confirmed_reservations' => $confirmed,
            // The single most useful number on the page: how often a request
            // actually turns into a booking.
            'conversion_rate' => $total > 0 ? round(($confirmed / $total) * 100) : 0,
            'total_users' => User::where('role', UserRole::User)->count(),
            'total_owners' => User::where('role', UserRole::Owner)->count(),
            'active_businesses' => Business::where('status', BusinessStatus::Active)->count(),
            'gross_booking_value' => (float) (clone $reservations)
                ->whereIn('status', [ReservationStatus::Confirmed, ReservationStatus::Completed])
                ->sum('total_price'),
        ];
    }

    private function scopedReservations(?Carbon $from, ?Carbon $to)
    {
        return Reservation::query()
            ->when($from, fn ($q) => $q->where('start_datetime', '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($q) => $q->where('start_datetime', '<=', $to->copy()->endOfDay()));
    }
}
