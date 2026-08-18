<?php

namespace Tests\Feature\Admin;

use App\Models\Holiday;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The platform-wide holiday calendar (pricing amendment) -- Admin-managed,
 * consulted by Spot::isWeekendRateDay() at booking time (see SpotPricingTest
 * for the pricing effect itself).
 */
class HolidayManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_admin_can_add_a_holiday(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.holidays.store'), [
                'date' => '2026-08-14',
                'name' => 'Independence Day',
            ])
            ->assertRedirect();

        $holiday = Holiday::sole();
        $this->assertSame('Independence Day', $holiday->name);
        $this->assertSame('2026-08-14', $holiday->date->toDateString());
    }

    #[Test]
    public function the_same_date_cannot_be_listed_twice(): void
    {
        $admin = User::factory()->admin()->create();
        Holiday::create(['date' => '2026-08-14', 'name' => 'Independence Day']);

        $this->actingAs($admin)
            ->post(route('admin.holidays.store'), [
                'date' => '2026-08-14',
                'name' => 'Duplicate',
            ])
            ->assertSessionHasErrors('date');

        $this->assertSame(1, Holiday::count());
    }

    #[Test]
    public function an_admin_can_remove_a_holiday(): void
    {
        $admin = User::factory()->admin()->create();
        $holiday = Holiday::create(['date' => '2026-08-14', 'name' => 'Independence Day']);

        $this->actingAs($admin)
            ->delete(route('admin.holidays.destroy', $holiday))
            ->assertRedirect();

        $this->assertDatabaseCount('holidays', 0);
    }

    #[Test]
    public function a_non_admin_cannot_manage_holidays(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.holidays.store'), ['date' => '2026-08-14', 'name' => 'X'])
            ->assertForbidden();
    }
}
