<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\Booking\ReservationService;
use Illuminate\Console\Command;

/**
 * FR-4.7 / SRS 9.2 -- release slots the Owner never answered for.
 *
 * Without this, unactioned requests sit pending forever. They would not block
 * the slot (only confirmed bookings do), but the customer would never learn
 * where they stand and the owner's queue would fill with dead requests.
 */
class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Expire pending reservations whose response window has closed';

    public function handle(ReservationService $reservations): int
    {
        $due = Reservation::query()
            ->where('status', ReservationStatus::Pending)
            ->where(function ($query) {
                $query->where('response_deadline', '<=', now())
                    // Safety net: a pending request whose slot has already
                    // started is dead regardless of what its deadline says.
                    ->orWhere('start_datetime', '<=', now());
            })
            ->with('spot', 'business.owner', 'user')
            ->get();

        foreach ($due as $reservation) {
            $reservations->expire($reservation);
        }

        if ($due->isNotEmpty()) {
            $this->info("Expired {$due->count()} reservation(s).");
        }

        return self::SUCCESS;
    }
}
