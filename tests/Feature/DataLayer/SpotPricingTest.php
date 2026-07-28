<?php

namespace Tests\Feature\DataLayer;

use App\Models\Spot;
use App\Support\OperatingHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SRS 9.7 -- duration must sit within the spot's bounds AND be an exact
 * multiple of the billing unit.
 */
class SpotPricingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_prices_by_whole_billing_units(): void
    {
        // Rs 100 per 10 minutes.
        $spot = Spot::factory()->snooker()->create();

        $this->assertEqualsWithDelta(300.0, $spot->priceFor(30), 0.001);
        $this->assertEqualsWithDelta(600.0, $spot->priceFor(60), 0.001);
        $this->assertEqualsWithDelta(900.0, $spot->priceFor(90), 0.001);
    }

    #[Test]
    public function a_partial_unit_rounds_up(): void
    {
        // Not reachable through validation, but the calculation must never
        // under-charge if it is ever called with an odd duration.
        $spot = Spot::factory()->snooker()->create();

        $this->assertEqualsWithDelta(300.0, $spot->priceFor(21), 0.001);
    }

    #[Test]
    public function hourly_spots_price_per_hour(): void
    {
        $spot = Spot::factory()->futsal()->create();

        $this->assertEqualsWithDelta(2500.0, $spot->priceFor(60), 0.001);
        $this->assertEqualsWithDelta(5000.0, $spot->priceFor(120), 0.001);
    }

    #[Test]
    public function allowed_durations_are_multiples_of_the_billing_unit_within_bounds(): void
    {
        $spot = Spot::factory()->pricedAt(100, 10)->duration(30, 60)->create();

        $this->assertSame([30, 40, 50, 60], $spot->allowedDurations());
    }

    #[Test]
    public function the_minimum_is_raised_to_the_next_whole_billing_unit(): void
    {
        // A 25-minute minimum on a 10-minute table cannot be honoured exactly,
        // so the first bookable duration is 30, not 25.
        $spot = Spot::factory()->pricedAt(100, 10)->duration(25, 60)->create();

        $this->assertSame(30, $spot->allowedDurations()[0]);
        $this->assertFalse($spot->isValidDuration(25));
    }

    #[Test]
    public function it_rejects_durations_that_are_not_a_valid_multiple(): void
    {
        $spot = Spot::factory()->snooker()->create();

        // The SRS's own example: you cannot book 7 minutes on a table billed
        // in 10-minute blocks.
        $this->assertFalse($spot->isValidDuration(7));
        $this->assertFalse($spot->isValidDuration(35));
        $this->assertTrue($spot->isValidDuration(40));
    }

    #[Test]
    public function it_rejects_durations_outside_the_min_max_bounds(): void
    {
        $spot = Spot::factory()->pricedAt(100, 10)->duration(30, 60)->create();

        $this->assertFalse($spot->isValidDuration(20), 'Below the minimum.');
        $this->assertFalse($spot->isValidDuration(70), 'Above the maximum.');
    }

    #[Test]
    public function a_spot_inherits_business_hours_when_it_has_no_override(): void
    {
        $spot = Spot::factory()->create();
        $spot->business->update(['operating_hours' => OperatingHours::everyDay('14:00', '02:00')]);

        $this->assertFalse($spot->fresh()->hasHoursOverride());
        $this->assertSame(
            [['open' => '14:00', 'close' => '02:00']],
            $spot->fresh(['business'])->effectiveHours()->forDay('mon')
        );
    }

    #[Test]
    public function an_override_takes_precedence_over_business_hours(): void
    {
        $spot = Spot::factory()->create([
            'operating_hours_override' => OperatingHours::everyDay('14:00', '22:00'),
        ]);
        $spot->business->update(['operating_hours' => OperatingHours::everyDay('14:00', '02:00')]);

        $this->assertTrue($spot->fresh()->hasHoursOverride());
        $this->assertSame(
            [['open' => '14:00', 'close' => '22:00']],
            $spot->fresh(['business'])->effectiveHours()->forDay('mon')
        );
    }

    #[Test]
    public function an_all_closed_override_is_distinct_from_having_no_override(): void
    {
        // This distinction matters: null means "inherit", an empty schedule
        // means "this spot is closed all week".
        $spot = Spot::factory()->create([
            'operating_hours_override' => OperatingHours::empty(),
        ]);
        $spot->business->update(['operating_hours' => OperatingHours::everyDay('14:00', '02:00')]);

        $this->assertTrue($spot->fresh()->hasHoursOverride());
        $this->assertTrue($spot->fresh(['business'])->effectiveHours()->isClosedAllWeek());
    }
}
