<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Notifications\ReservationReminder;
use Illuminate\Console\Command;

/**
 * FR-5.1 -- remind the customer (and the Owner) before a confirmed booking.
 *
 * `reminder_sent_at` is the idempotency guard: this runs every minute, and
 * without it a booking two hours out would be reminded about sixty times.
 */
class SendBookingReminders extends Command
{
    protected $signature = 'reservations:remind';

    protected $description = 'Send reminders for upcoming confirmed reservations';

    public function handle(): int
    {
        $window = now()->addHours((int) config('booking.reminder_hours_before'));

        $due = Reservation::query()
            ->where('status', ReservationStatus::Confirmed)
            ->whereNull('reminder_sent_at')
            ->where('start_datetime', '>', now())
            ->where('start_datetime', '<=', $window)
            ->with('spot', 'business.owner', 'user')
            ->get();

        foreach ($due as $reservation) {
            // A walk-in or phone booking with no linked account has nobody to
            // remind -- the venue is the one who took it in the first place.
            $reservation->user?->notify(new ReservationReminder($reservation));
            $reservation->business->owner->notify(new ReservationReminder($reservation));

            $reservation->forceFill(['reminder_sent_at' => now()])->save();
        }

        if ($due->isNotEmpty()) {
            $this->info("Sent {$due->count()} reminder(s).");
        }

        return self::SUCCESS;
    }
}
