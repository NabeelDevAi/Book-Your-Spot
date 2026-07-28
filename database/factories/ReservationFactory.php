<?php

namespace Database\Factories;

use App\Enums\RejectionReason;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\User;
use App\Services\Booking\DeadlineCalculator;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    public function definition(): array
    {
        // Default to a slot tomorrow evening: comfortably in the future, and
        // far enough out that the deadline lands on the fixed-lead branch.
        $start = Carbon::tomorrow()->setTime(19, 0);
        $duration = 60;
        $end = $start->copy()->addMinutes($duration);

        return [
            'spot_id' => Spot::factory(),
            'user_id' => User::factory()->state(['role' => UserRole::User]),
            'business_id' => fn (array $attributes) => Spot::find($attributes['spot_id'])?->business_id
                ?? Spot::factory()->create()->business_id,
            'start_datetime' => $start,
            'end_datetime' => $end,
            'duration_minutes' => $duration,
            'price_amount_snapshot' => 2500,
            'price_unit_minutes_snapshot' => 60,
            'total_price' => 2500,
            'status' => ReservationStatus::Pending,
            'response_deadline' => fn (array $attributes) => app(DeadlineCalculator::class)
                ->for(Carbon::parse($attributes['start_datetime'])),
            'requested_at' => now(),
        ];
    }

    /**
     * Place the booking at a specific time, recomputing everything that derives
     * from it. Use this rather than overriding start_datetime directly, or the
     * deadline and end time silently drift out of sync.
     */
    public function at(Carbon $start, int $durationMinutes = 60): static
    {
        return $this->state(fn () => [
            'start_datetime' => $start->copy(),
            'end_datetime' => $start->copy()->addMinutes($durationMinutes),
            'duration_minutes' => $durationMinutes,
            'response_deadline' => app(DeadlineCalculator::class)->for($start->copy()),
        ]);
    }

    public function forSpot(Spot $spot): static
    {
        return $this->state(fn () => [
            'spot_id' => $spot->id,
            'business_id' => $spot->business_id,
            'price_amount_snapshot' => $spot->price_amount,
            'price_unit_minutes_snapshot' => $spot->price_unit_minutes,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => ReservationStatus::Confirmed,
            'responded_at' => now(),
        ]);
    }

    public function rejected(RejectionReason $reason = RejectionReason::FullyBooked): static
    {
        return $this->state(fn () => [
            'status' => ReservationStatus::Rejected,
            'responded_at' => now(),
            'rejection_reason_code' => $reason,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['status' => ReservationStatus::Expired]);
    }

    public function cancelled(bool $late = false): static
    {
        return $this->state(fn () => [
            'status' => ReservationStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by_role' => UserRole::User,
            'is_late_cancellation' => $late,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['status' => ReservationStatus::Completed]);
    }

    public function noShow(): static
    {
        return $this->state(fn () => [
            'status' => ReservationStatus::NoShow,
            'no_show_flagged_at' => now(),
        ]);
    }

    /** A pending request whose response window has already lapsed. */
    public function overdue(): static
    {
        return $this->state(fn () => [
            'status' => ReservationStatus::Pending,
            'response_deadline' => now()->subHour(),
        ]);
    }

    /** A booking that finished in the past -- for the auto-complete sweep. */
    public function past(int $daysAgo = 1): static
    {
        $start = Carbon::today()->subDays($daysAgo)->setTime(19, 0);

        return $this->state(fn () => [
            'start_datetime' => $start,
            'end_datetime' => $start->copy()->addHour(),
            'duration_minutes' => 60,
            'response_deadline' => $start->copy()->subHours(12),
            'requested_at' => $start->copy()->subDay(),
        ]);
    }
}
