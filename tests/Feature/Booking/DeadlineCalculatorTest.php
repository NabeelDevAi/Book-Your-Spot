<?php

namespace Tests\Feature\Booking;

use App\Services\Booking\DeadlineCalculator;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SRS 9.2 -- the owner response window.
 *
 * Config for reference: lead 12h, window 2h, late floor 60m, buffer 15m.
 */
class DeadlineCalculatorTest extends TestCase
{
    private DeadlineCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new DeadlineCalculator;
    }

    #[Test]
    public function a_far_out_booking_expires_at_the_fixed_lead_time(): void
    {
        // Requested Thursday for a slot on Saturday evening.
        $now = Carbon::parse('2026-08-06 10:00:00');
        $start = Carbon::parse('2026-08-08 20:00:00');

        $deadline = $this->calculator->for($start, $now);

        // start - 12h
        $this->assertSame('2026-08-08 08:00:00', $deadline->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function a_same_day_booking_falls_back_to_the_short_response_window(): void
    {
        // 5 hours out: inside the 12h lead, so the owner gets 2 hours to decide.
        $now = Carbon::parse('2026-08-08 15:00:00');
        $start = Carbon::parse('2026-08-08 20:00:00');

        $deadline = $this->calculator->for($start, $now);

        $this->assertSame('2026-08-08 17:00:00', $deadline->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_late_floor_wins_when_the_two_hour_window_would_overrun_the_slot(): void
    {
        // 90 minutes out: now + 2h would be 30 minutes AFTER the slot starts,
        // so the floor (start - 1h) applies instead.
        $now = Carbon::parse('2026-08-08 18:30:00');
        $start = Carbon::parse('2026-08-08 20:00:00');

        $deadline = $this->calculator->for($start, $now);

        $this->assertSame('2026-08-08 19:00:00', $deadline->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function a_last_minute_booking_is_never_created_already_expired(): void
    {
        // 40 minutes out. The raw floor would be 19:00 -- already 20 minutes in
        // the past -- which would make the very next scheduler tick expire this
        // reservation before the owner ever saw it. The buffer clamp prevents it.
        $now = Carbon::parse('2026-08-08 19:20:00');
        $start = Carbon::parse('2026-08-08 20:00:00');

        $deadline = $this->calculator->for($start, $now);

        $this->assertTrue($deadline->greaterThan($now), 'Deadline must be in the future.');
        $this->assertSame('2026-08-08 19:35:00', $deadline->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_deadline_never_falls_after_the_slot_start(): void
    {
        // Exactly at the minimum booking lead: the buffer would push past the
        // start, so it is clamped back to the start itself.
        $now = Carbon::parse('2026-08-08 19:50:00');
        $start = Carbon::parse('2026-08-08 20:00:00');

        $deadline = $this->calculator->for($start, $now);

        $this->assertTrue($deadline->lessThanOrEqualTo($start));
        $this->assertSame('2026-08-08 20:00:00', $deadline->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_boundary_between_the_two_branches_is_exactly_the_lead_time(): void
    {
        $start = Carbon::parse('2026-08-08 20:00:00');

        // Comfortably before the boundary -> fixed-lead branch.
        $before = $this->calculator->for($start, Carbon::parse('2026-08-08 07:00:00'));
        $this->assertSame('2026-08-08 08:00:00', $before->format('Y-m-d H:i:s'));

        // Exactly at the boundary -> short-window branch (now + 2h).
        $atBoundary = $this->calculator->for($start, Carbon::parse('2026-08-08 08:00:00'));
        $this->assertSame('2026-08-08 10:00:00', $atBoundary->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_buffer_wins_when_the_lead_deadline_is_seconds_away(): void
    {
        // Requested one second before the 12h lead deadline. Honouring the lead
        // literally would give the owner a one-second window, so the minimum
        // buffer takes over. The buffer is a floor on every branch, not just
        // the late-booking one.
        $now = Carbon::parse('2026-08-08 07:59:59');
        $start = Carbon::parse('2026-08-08 20:00:00');

        $deadline = $this->calculator->for($start, $now);

        $this->assertSame('2026-08-08 08:14:59', $deadline->format('Y-m-d H:i:s'));
        $this->assertSame(15, (int) $now->diffInMinutes($deadline));
    }

    #[Test]
    public function it_defaults_to_the_current_time_when_no_request_time_is_given(): void
    {
        $this->travelTo(Carbon::parse('2026-08-06 10:00:00'));

        $deadline = $this->calculator->for(Carbon::parse('2026-08-08 20:00:00'));

        $this->assertSame('2026-08-08 08:00:00', $deadline->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_does_not_mutate_the_arguments_it_is_given(): void
    {
        $now = Carbon::parse('2026-08-06 10:00:00');
        $start = Carbon::parse('2026-08-08 20:00:00');

        $this->calculator->for($start, $now);

        $this->assertSame('2026-08-06 10:00:00', $now->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-08 20:00:00', $start->format('Y-m-d H:i:s'));
    }
}
