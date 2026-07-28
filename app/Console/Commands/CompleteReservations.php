<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\Booking\ReservationService;
use Illuminate\Console\Command;

/**
 * FR-4.10 -- a confirmed booking whose end time has passed becomes `completed`.
 *
 * Deliberately gives the Owner a grace period before auto-completing, so a
 * booking that ended twenty minutes ago can still be flagged as a no-show. Without
 * it, this command would race the owner to the record and quietly overwrite a
 * no-show that had not been submitted yet.
 */
class CompleteReservations extends Command
{
    protected $signature = 'reservations:complete {--grace=120 : Minutes to wait after the end time}';

    protected $description = 'Mark finished confirmed reservations as completed';

    public function handle(ReservationService $reservations): int
    {
        $cutoff = now()->subMinutes((int) $this->option('grace'));

        $due = Reservation::query()
            ->where('status', ReservationStatus::Confirmed)
            ->where('end_datetime', '<=', $cutoff)
            ->get();

        foreach ($due as $reservation) {
            $reservations->complete($reservation);
        }

        if ($due->isNotEmpty()) {
            $this->info("Completed {$due->count()} reservation(s).");
        }

        return self::SUCCESS;
    }
}
