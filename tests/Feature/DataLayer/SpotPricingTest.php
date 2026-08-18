<?php

namespace Tests\Feature\DataLayer;

use App\Models\Holiday;
use App\Models\Spot;
use App\Support\OperatingHours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SRS 9.7 -- duration must be at least the spot's minimum AND an exact
 * multiple of the billing unit. There is no maximum any more (SRS
 * amendment); how long a booking can run is bounded only by the day's
 * operating hours, not a field the Owner sets.
 *
 * Also covers the weekday/weekend pricing amendment: a Spot may bill a
 * different rate on Sat/Sun, a listed Holiday, and the day immediately
 * before a Holiday.
 */
class SpotPricingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_prices_by_whole_billing_units(): void
    {
        // Rs 100 per 10 minutes.
        $spot = Spot::factory()->snooker()->create();
        $aMonday = Carbon::parse('2026-08-03');

        $this->assertEqualsWithDelta(300.0, $spot->priceFor($aMonday, 30), 0.001);
        $this->assertEqualsWithDelta(600.0, $spot->priceFor($aMonday, 60), 0.001);
        $this->assertEqualsWithDelta(900.0, $spot->priceFor($aMonday, 90), 0.001);
    }

    #[Test]
    public function a_partial_unit_rounds_up(): void
    {
        // Not reachable through validation, but the calculation must never
        // under-charge if it is ever called with an odd duration.
        $spot = Spot::factory()->snooker()->create();

        $this->assertEqualsWithDelta(300.0, $spot->priceFor(Carbon::parse('2026-08-03'), 21), 0.001);
    }

    #[Test]
    public function hourly_spots_price_per_hour(): void
    {
        $spot = Spot::factory()->futsal()->create();
        $aMonday = Carbon::parse('2026-08-03');

        $this->assertEqualsWithDelta(2500.0, $spot->priceFor($aMonday, 60), 0.001);
        $this->assertEqualsWithDelta(5000.0, $spot->priceFor($aMonday, 120), 0.001);
    }

    /*
    |--------------------------------------------------------------------------
    | Weekday / weekend / holiday pricing
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function weekends_bill_the_weekend_rate_when_one_is_set(): void
    {
        $spot = Spot::factory()->futsal()->weekendPricedAt(4000)->create();

        $this->assertEqualsWithDelta(2500.0, $spot->rateFor(Carbon::parse('2026-08-03')), 0.001); // Monday
        $this->assertEqualsWithDelta(4000.0, $spot->rateFor(Carbon::parse('2026-08-01')), 0.001); // Saturday
        $this->assertEqualsWithDelta(4000.0, $spot->rateFor(Carbon::parse('2026-08-02')), 0.001); // Sunday
    }

    #[Test]
    public function with_no_weekend_rate_set_the_weekday_rate_applies_every_day(): void
    {
        $spot = Spot::factory()->futsal()->create(); // weekend_price_amount left null

        $this->assertFalse($spot->hasDistinctWeekendRate());
        $this->assertEqualsWithDelta(2500.0, $spot->rateFor(Carbon::parse('2026-08-01')), 0.001); // Saturday
    }

    #[Test]
    public function a_listed_holiday_bills_the_weekend_rate_even_on_a_weekday(): void
    {
        Holiday::create(['date' => '2026-08-14', 'name' => 'Independence Day']); // a Friday

        $spot = Spot::factory()->futsal()->weekendPricedAt(4000)->create();

        $this->assertEqualsWithDelta(4000.0, $spot->rateFor(Carbon::parse('2026-08-14')), 0.001);
    }

    #[Test]
    public function the_day_before_a_holiday_also_bills_the_weekend_rate(): void
    {
        Holiday::create(['date' => '2026-08-14', 'name' => 'Independence Day']); // a Friday

        $spot = Spot::factory()->futsal()->weekendPricedAt(4000)->create();

        $this->assertEqualsWithDelta(4000.0, $spot->rateFor(Carbon::parse('2026-08-13')), 0.001);
        // Two days before is an ordinary Wednesday -- unaffected.
        $this->assertEqualsWithDelta(2500.0, $spot->rateFor(Carbon::parse('2026-08-12')), 0.001);
    }

    /*
    |--------------------------------------------------------------------------
    | Duration bounds -- minimum only
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function allowed_durations_are_multiples_of_the_billing_unit_up_to_the_days_open_window(): void
    {
        $spot = Spot::factory()->pricedAt(100, 10)->minDuration(30)->create();
        $spot->business->update(['operating_hours' => OperatingHours::everyDay('10:00', '11:00')]); // 60-minute window

        $this->assertSame([30, 40, 50, 60], $spot->fresh(['business'])->allowedDurations());
    }

    #[Test]
    public function there_is_no_upper_bound_other_than_the_open_window(): void
    {
        // No owner-set maximum (SRS amendment): a long window allows a long list.
        $spot = Spot::factory()->pricedAt(100, 10)->minDuration(30)->create();
        $spot->business->update(['operating_hours' => OperatingHours::everyDay('10:00', '23:00')]); // 13 hours

        $durations = $spot->fresh(['business'])->allowedDurations();

        $this->assertSame(30, $durations[0]);
        $this->assertSame(780, end($durations)); // the full 13-hour window, in 10-minute steps
        $this->assertTrue($spot->fresh(['business'])->isValidDuration(780));
    }

    #[Test]
    public function the_minimum_is_raised_to_the_next_whole_billing_unit(): void
    {
        // A 25-minute minimum on a 10-minute table cannot be honoured exactly,
        // so the first bookable duration is 30, not 25.
        $spot = Spot::factory()->pricedAt(100, 10)->minDuration(25)->create();

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
    public function it_rejects_only_durations_below_the_minimum(): void
    {
        $spot = Spot::factory()->pricedAt(100, 10)->minDuration(30)->create();

        $this->assertFalse($spot->isValidDuration(20), 'Below the minimum.');
        // No maximum any more -- a long duration is a valid multiple.
        $this->assertTrue($spot->isValidDuration(600));
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
