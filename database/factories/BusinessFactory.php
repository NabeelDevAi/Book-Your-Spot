<?php

namespace Database\Factories;

use App\Enums\BusinessStatus;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\User;
use App\Support\OperatingHours;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Business>
 */
class BusinessFactory extends Factory
{
    /** Real Karachi localities, so seeded data looks like the pilot city. */
    public const AREAS = [
        'DHA Phase 5', 'DHA Phase 6', 'Clifton', 'Gulshan-e-Iqbal',
        'North Nazimabad', 'Bahadurabad', 'PECHS', 'Gulistan-e-Johar',
        'Malir Cantt', 'Nazimabad',
    ];

    public function definition(): array
    {
        return [
            'owner_id' => User::factory()->state(['role' => UserRole::Owner]),
            'name' => fake()->company().' '.fake()->randomElement(['Arena', 'Zone', 'Club', 'Courts', 'Lounge']),
            'description' => fake()->paragraph(),
            'address' => fake()->buildingNumber().', '.fake()->streetName(),
            'city' => 'Karachi',
            'area' => fake()->randomElement(self::AREAS),
            'contact_number' => '+9221'.fake()->numerify('#######'),
            'status' => BusinessStatus::PendingReview,
            // Evening-heavy hours that run past midnight -- the normal shape for
            // this kind of venue, and useful for exercising the wrap-around
            // handling in OperatingHours.
            'operating_hours' => OperatingHours::everyDay('14:00', '02:00'),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => BusinessStatus::Active,
            'reviewed_at' => now(),
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn () => ['status' => BusinessStatus::PendingReview]);
    }

    public function rejected(?string $reason = null): static
    {
        return $this->state(fn () => [
            'status' => BusinessStatus::Rejected,
            'rejection_reason' => $reason ?? 'Insufficient venue details provided.',
            'reviewed_at' => now(),
        ]);
    }

    public function suspended(?string $reason = null): static
    {
        return $this->state(fn () => [
            'status' => BusinessStatus::Suspended,
            'suspension_reason' => $reason ?? 'Multiple unresolved customer complaints.',
        ]);
    }

    public function duplicateFlagged(): static
    {
        return $this->state(fn () => [
            'duplicate_flagged' => true,
            'duplicate_note' => 'Contact number matches an existing listing.',
        ]);
    }

    public function hours(string $open, string $close): static
    {
        return $this->state(fn () => [
            'operating_hours' => OperatingHours::everyDay($open, $close),
        ]);
    }

    public function for_owner(User $owner): static
    {
        return $this->state(fn () => ['owner_id' => $owner->id]);
    }
}
