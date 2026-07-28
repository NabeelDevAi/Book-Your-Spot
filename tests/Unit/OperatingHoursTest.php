<?php

namespace Tests\Unit;

use App\Support\OperatingHours;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OperatingHoursTest extends TestCase
{
    #[Test]
    public function it_normalises_loosely_formatted_times(): void
    {
        $hours = OperatingHours::fromArray([
            'mon' => [['open' => '9:00', 'close' => '17:00:00']],
        ]);

        $this->assertSame(
            [['open' => '09:00', 'close' => '17:00']],
            $hours->forDay('mon')
        );
    }

    #[Test]
    public function it_discards_malformed_and_zero_length_ranges(): void
    {
        $hours = OperatingHours::fromArray([
            'mon' => [
                ['open' => '25:00', 'close' => '17:00'],   // impossible hour
                ['open' => '10:00'],                        // missing close
                ['open' => '10:00', 'close' => '10:00'],    // zero length
                ['open' => '10:00', 'close' => '18:00'],    // the only valid one
            ],
        ]);

        $this->assertSame([['open' => '10:00', 'close' => '18:00']], $hours->forDay('mon'));
    }

    #[Test]
    public function a_missing_day_is_treated_as_closed(): void
    {
        $hours = OperatingHours::fromArray(['mon' => [['open' => '10:00', 'close' => '18:00']]]);

        $this->assertTrue($hours->isClosedOn('sun'));
        $this->assertFalse($hours->isClosedOn('mon'));
    }

    #[Test]
    public function it_supports_split_shifts(): void
    {
        $hours = OperatingHours::fromArray([
            'sat' => [
                ['open' => '10:00', 'close' => '13:00'],
                ['open' => '16:00', 'close' => '23:00'],
            ],
        ]);

        $this->assertCount(2, $hours->forDay('sat'));
    }

    #[Test]
    public function a_closing_time_before_the_opening_time_runs_past_midnight(): void
    {
        // The normal shape for a gaming venue: open 4pm, close 2am the next day.
        $hours = OperatingHours::everyDay('16:00', '02:00');

        $windows = $hours->windowsFor(Carbon::parse('2026-08-01 00:00:00'));

        $this->assertCount(1, $windows);
        $this->assertSame('2026-08-01 16:00:00', $windows[0]['start']->format('Y-m-d H:i:s'));
        // Ends on the FOLLOWING day, not the same one.
        $this->assertSame('2026-08-02 02:00:00', $windows[0]['end']->format('Y-m-d H:i:s'));
        $this->assertSame(600, (int) $windows[0]['start']->diffInMinutes($windows[0]['end']));
    }

    #[Test]
    public function a_same_day_window_does_not_roll_over(): void
    {
        $windows = OperatingHours::everyDay('10:00', '18:00')
            ->windowsFor(Carbon::parse('2026-08-01 00:00:00'));

        $this->assertSame('2026-08-01 18:00:00', $windows[0]['end']->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function windows_resolve_against_the_correct_weekday(): void
    {
        $hours = OperatingHours::fromArray([
            'sat' => [['open' => '08:00', 'close' => '12:00']],
            'sun' => [],
        ]);

        // 1 Aug 2026 is a Saturday, 2 Aug is a Sunday.
        $this->assertCount(1, $hours->windowsFor(Carbon::parse('2026-08-01')));
        $this->assertCount(0, $hours->windowsFor(Carbon::parse('2026-08-02')));
    }

    #[Test]
    public function the_summary_collapses_consecutive_days_with_identical_hours(): void
    {
        $summary = OperatingHours::everyDay('16:00', '23:00')->summary();

        $this->assertSame('Mon–Sun 4:00 PM – 11:00 PM', $summary);
    }

    #[Test]
    public function the_summary_splits_groups_when_hours_differ(): void
    {
        $hours = OperatingHours::fromArray([
            'mon' => [['open' => '16:00', 'close' => '23:00']],
            'tue' => [['open' => '16:00', 'close' => '23:00']],
            'wed' => [['open' => '16:00', 'close' => '23:00']],
            'thu' => [['open' => '16:00', 'close' => '23:00']],
            'fri' => [['open' => '16:00', 'close' => '23:00']],
            'sat' => [['open' => '10:00', 'close' => '02:00']],
            'sun' => [],
        ]);

        $summary = $hours->summary();

        $this->assertStringContainsString('Mon–Fri 4:00 PM – 11:00 PM', $summary);
        $this->assertStringContainsString('Sat 10:00 AM – 2:00 AM', $summary);
        $this->assertStringContainsString('Sun closed', $summary);
    }

    #[Test]
    public function it_formats_midnight_and_noon_correctly(): void
    {
        // The classic 12-hour clock trap: hour 0 and hour 12 both map to "12".
        $this->assertSame('12:00 AM', OperatingHours::displayTime('00:00'));
        $this->assertSame('12:30 PM', OperatingHours::displayTime('12:30'));
        $this->assertSame('1:05 AM', OperatingHours::displayTime('01:05'));
        $this->assertSame('11:45 PM', OperatingHours::displayTime('23:45'));
    }

    #[Test]
    public function an_empty_schedule_is_closed_all_week(): void
    {
        $this->assertTrue(OperatingHours::empty()->isClosedAllWeek());
        $this->assertFalse(OperatingHours::everyDay('10:00', '18:00')->isClosedAllWeek());
    }

    #[Test]
    public function it_round_trips_through_to_array(): void
    {
        $original = OperatingHours::everyDay('14:00', '02:00');
        $restored = OperatingHours::fromArray($original->toArray());

        $this->assertSame($original->toArray(), $restored->toArray());
    }
}
