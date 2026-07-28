<?php

namespace Tests\Feature\DataLayer;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\Spot;
use App\Models\SpotBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReservationModelTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_generates_a_unique_human_readable_reference(): void
    {
        $reservation = Reservation::factory()->create();

        $this->assertMatchesRegularExpression('/^BYS-[A-Z2-9]{6}$/', $reservation->reference);
    }

    #[Test]
    public function references_exclude_characters_that_are_ambiguous_when_read_aloud(): void
    {
        // The reference gets spoken across a counter and typed by a busy owner,
        // so 0/O and 1/I must never appear.
        $references = Reservation::factory()->count(25)->create()->pluck('reference');

        foreach ($references as $reference) {
            $code = substr($reference, 4);
            $this->assertDoesNotMatchRegularExpression('/[01OI]/', $code, "Ambiguous character in {$reference}");
        }

        $this->assertSame(25, $references->unique()->count(), 'References must be unique.');
    }

    /*
    |--------------------------------------------------------------------------
    | Overlap semantics -- the foundation of the double-booking guarantee
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function back_to_back_bookings_do_not_overlap(): void
    {
        $spot = Spot::factory()->create();
        $start = Carbon::tomorrow()->setTime(18, 0);

        Reservation::factory()->forSpot($spot)->at($start, 60)->confirmed()->create();

        // The next booking starts exactly when the first ends. A half-open
        // interval [start, end) is what makes consecutive slots sellable at all.
        $overlapping = Reservation::query()
            ->forSpot($spot->id)
            ->blocking()
            ->overlapping($start->copy()->addHour(), $start->copy()->addHours(2))
            ->count();

        $this->assertSame(0, $overlapping);
    }

    #[Test]
    public function a_partially_overlapping_booking_is_detected(): void
    {
        $spot = Spot::factory()->create();
        $start = Carbon::tomorrow()->setTime(18, 0);

        Reservation::factory()->forSpot($spot)->at($start, 60)->confirmed()->create();

        // Starts 30 minutes into the existing booking.
        $overlapping = Reservation::query()
            ->forSpot($spot->id)
            ->blocking()
            ->overlapping($start->copy()->addMinutes(30), $start->copy()->addMinutes(90))
            ->count();

        $this->assertSame(1, $overlapping);
    }

    #[Test]
    public function a_fully_enclosed_booking_is_detected(): void
    {
        $spot = Spot::factory()->create();
        $start = Carbon::tomorrow()->setTime(18, 0);

        Reservation::factory()->forSpot($spot)->at($start, 180)->confirmed()->create();

        $overlapping = Reservation::query()
            ->forSpot($spot->id)
            ->blocking()
            ->overlapping($start->copy()->addHour(), $start->copy()->addHours(2))
            ->count();

        $this->assertSame(1, $overlapping);
    }

    #[Test]
    public function pending_reservations_do_not_block_the_slot(): void
    {
        // This is the amendment to FR-4.4/NFR-1: several users may hold pending
        // requests on one slot, and the first approval wins. If pending blocked,
        // the "all other pendings auto-reject" rule could never fire.
        $spot = Spot::factory()->create();
        $start = Carbon::tomorrow()->setTime(18, 0);

        Reservation::factory()->forSpot($spot)->at($start, 60)->create();
        Reservation::factory()->forSpot($spot)->at($start, 60)->create();

        $blocking = Reservation::query()
            ->forSpot($spot->id)
            ->blocking()
            ->overlapping($start, $start->copy()->addHour())
            ->count();

        $this->assertSame(0, $blocking, 'Pending reservations must not block.');
        $this->assertSame(2, Reservation::pending()->count());
    }

    #[Test]
    public function terminal_reservations_do_not_block_the_slot(): void
    {
        $spot = Spot::factory()->create();
        $start = Carbon::tomorrow()->setTime(18, 0);

        Reservation::factory()->forSpot($spot)->at($start, 60)->cancelled()->create();
        Reservation::factory()->forSpot($spot)->at($start, 60)->rejected()->create();
        Reservation::factory()->forSpot($spot)->at($start, 60)->expired()->create();

        $blocking = Reservation::query()
            ->forSpot($spot->id)
            ->blocking()
            ->overlapping($start, $start->copy()->addHour())
            ->count();

        $this->assertSame(0, $blocking, 'A released slot must be bookable again.');
    }

    #[Test]
    public function spot_blocks_use_the_same_half_open_overlap_rule(): void
    {
        $spot = Spot::factory()->create();
        $start = Carbon::tomorrow()->setTime(12, 0);

        SpotBlock::factory()->forSpot($spot)->at($start, 120)->create();

        $touching = SpotBlock::query()
            ->forSpot($spot->id)
            ->overlapping($start->copy()->addHours(2), $start->copy()->addHours(3))
            ->count();

        $clashing = SpotBlock::query()
            ->forSpot($spot->id)
            ->overlapping($start->copy()->addHour(), $start->copy()->addHours(3))
            ->count();

        $this->assertSame(0, $touching, 'A booking starting when a block ends is fine.');
        $this->assertSame(1, $clashing);
    }

    /*
    |--------------------------------------------------------------------------
    | Status behaviour
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function only_pending_and_confirmed_reservations_are_cancellable(): void
    {
        $this->assertTrue(ReservationStatus::Pending->isCancellable());
        $this->assertTrue(ReservationStatus::Confirmed->isCancellable());

        foreach ([ReservationStatus::Rejected, ReservationStatus::Expired,
            ReservationStatus::Cancelled, ReservationStatus::Completed,
            ReservationStatus::NoShow] as $status) {
            $this->assertFalse($status->isCancellable(), "{$status->value} must not be cancellable.");
            $this->assertTrue($status->isTerminal(), "{$status->value} must be terminal.");
        }
    }

    #[Test]
    public function a_pending_reservation_past_its_deadline_reports_as_expired(): void
    {
        $reservation = Reservation::factory()->overdue()->create();

        $this->assertTrue($reservation->hasExpired());
    }

    #[Test]
    public function a_confirmed_reservation_past_its_deadline_has_not_expired(): void
    {
        // Expiry only applies to requests the owner never answered.
        $reservation = Reservation::factory()->confirmed()->create([
            'response_deadline' => now()->subHour(),
        ]);

        $this->assertFalse($reservation->hasExpired());
    }

    #[Test]
    public function a_cancellation_inside_the_cutoff_is_recognised_as_late(): void
    {
        // Cutoff is 1 hour before start.
        $soon = Reservation::factory()->at(now()->addMinutes(30), 60)->confirmed()->create();
        $later = Reservation::factory()->at(now()->addHours(5), 60)->confirmed()->create();

        $this->assertTrue($soon->isWithinCancellationCutoff());
        $this->assertFalse($later->isWithinCancellationCutoff());
    }

    #[Test]
    public function the_price_snapshot_survives_a_later_price_change(): void
    {
        // SRS 9.10: changing a spot's price must not rewrite what an already
        // confirmed customer agreed to pay.
        $spot = Spot::factory()->futsal()->create();

        $reservation = Reservation::factory()->forSpot($spot)->confirmed()->create([
            'total_price' => $spot->priceFor(60),
        ]);

        $spot->update(['price_amount' => 5000]);

        $this->assertEqualsWithDelta(2500.0, (float) $reservation->fresh()->total_price, 0.001);
        $this->assertEqualsWithDelta(2500.0, (float) $reservation->fresh()->price_amount_snapshot, 0.001);
        $this->assertEqualsWithDelta(5000.0, (float) $spot->fresh()->price_amount, 0.001);
    }
}
