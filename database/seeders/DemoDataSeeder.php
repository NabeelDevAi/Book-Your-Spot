<?php

namespace Database\Seeders;

use App\Enums\ConflictSource;
use App\Enums\RejectionReason;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Business;
use App\Models\BusinessGame;
use App\Models\Game;
use App\Models\Holiday;
use App\Models\Reservation;
use App\Models\ReservationConflict;
use App\Models\Spot;
use App\Models\SpotBlock;
use App\Models\User;
use App\Services\Booking\DeadlineCalculator;
use App\Support\OperatingHours;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * A pilot-shaped dataset for local development and demos.
 *
 * Deliberately not uniform: it includes a business in every status, a spot with
 * its own hours override, a repeat-no-show customer, an unresolved conflict and
 * reservations in all seven states. The point is that every screen we build
 * later has something real to render, including its unhappy paths.
 */
class DemoDataSeeder extends Seeder
{
    private DeadlineCalculator $deadlines;

    public function run(): void
    {
        $this->deadlines = app(DeadlineCalculator::class);

        $owners = $this->createOwners();
        $customers = $this->createCustomers();

        $cueConsole = $this->createCueAndConsole($owners['zain']);
        $turfArena = $this->createTurfArena($owners['sana']);
        $this->createPadelClub($owners['sana']);
        $this->createPendingAndProblemBusinesses($owners['bilal']);

        $this->createReservations($cueConsole, $turfArena, $customers);
        $this->createBlocksAndConflicts($cueConsole, $customers);
        $this->createHolidays();
    }

    /** A couple of Pakistan public holidays, so the weekend-pricing amendment has something to show. */
    private function createHolidays(): void
    {
        Holiday::updateOrCreate(['date' => '2026-08-14'], ['name' => 'Independence Day']);
        Holiday::updateOrCreate(['date' => '2026-12-25'], ['name' => 'Quaid-e-Azam Day']);
    }

    /** @return array<string, User> */
    private function createOwners(): array
    {
        $make = fn (string $name, string $email, string $phone) => User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'phone' => $phone,
                'password' => Hash::make('password'),
                'role' => UserRole::Owner,
                'email_verified_at' => now(),
            ],
        );

        return [
            'zain' => $make('Zain Ahmed', 'zain@cueconsole.pk', '+923002001001'),
            'sana' => $make('Sana Khan', 'sana@turfarena.pk', '+923002001002'),
            'bilal' => $make('Bilal Sheikh', 'bilal@playzone.pk', '+923002001003'),
        ];
    }

    /** @return array<string, User> */
    private function createCustomers(): array
    {
        $make = fn (string $name, string $email, string $phone, int $noShows = 0) => User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'phone' => $phone,
                'password' => Hash::make('password'),
                'role' => UserRole::User,
                'no_show_count' => $noShows,
                'email_verified_at' => now(),
            ],
        );

        return [
            'ali' => $make('Ali Raza', 'ali@example.com', '+923003001001'),
            'hina' => $make('Hina Malik', 'hina@example.com', '+923003001002'),
            'omar' => $make('Omar Farooq', 'omar@example.com', '+923003001003'),
            // Exercises the SRS 9.12 warning shown to Owners at approval time.
            'danish' => $make('Danish Iqbal', 'danish@example.com', '+923003001004', 3),
        ];
    }

    /** A snooker + PS5 venue, billed in fine-grained blocks. */
    private function createCueAndConsole(User $owner): Business
    {
        $business = $this->makeBusiness($owner, [
            'name' => 'Cue & Console Gaming Zone',
            'description' => 'Eight snooker tables and four PS5 rooms in the heart of DHA. '
                .'Air-conditioned, cafe on site, open late every night.',
            'address' => '12-C, Khayaban-e-Bukhari, Phase 6',
            'area' => 'DHA Phase 6',
            'contact_number' => '+922135840001',
            'hours' => OperatingHours::everyDay('14:00', '02:00'),
        ]);

        $snooker = $this->attachGame($business, 'snooker');

        foreach (range(1, 4) as $n) {
            Spot::factory()->snooker($n)->create([
                'business_game_id' => $snooker->id,
                'business_id' => $business->id,
                'sort_order' => $n,
            ]);
        }

        // One table closes earlier than the venue -- exercises the per-spot
        // hours override (FR-2.4).
        Spot::factory()->snooker(5)->create([
            'business_game_id' => $snooker->id,
            'business_id' => $business->id,
            'sort_order' => 5,
            'operating_hours_override' => OperatingHours::everyDay('14:00', '22:00'),
        ]);

        // Taken out of service without being deleted (SRS 9.9).
        Spot::factory()->snooker(6)->inactive()->create([
            'business_game_id' => $snooker->id,
            'business_id' => $business->id,
            'sort_order' => 6,
        ]);

        $ps5 = $this->attachGame($business, 'ps5', 1);

        foreach (['A', 'B', 'C'] as $i => $room) {
            Spot::factory()->ps5($room)->create([
                'business_game_id' => $ps5->id,
                'business_id' => $business->id,
                'sort_order' => $i,
            ]);
        }

        return $business;
    }

    /** A futsal venue billed by the hour. */
    private function createTurfArena(User $owner): Business
    {
        $business = $this->makeBusiness($owner, [
            'name' => 'Turf Arena Sports Complex',
            'description' => 'Two floodlit rooftop futsal courts with FIFA-standard turf.',
            'address' => 'Plot 44, Block 13-D, Gulshan-e-Iqbal',
            'area' => 'Gulshan-e-Iqbal',
            'contact_number' => '+922134980002',
            'hours' => OperatingHours::everyDay('16:00', '01:00'),
        ]);

        $futsal = $this->attachGame($business, 'futsal');

        foreach (range(1, 2) as $n) {
            Spot::factory()->futsal($n)->create([
                'business_game_id' => $futsal->id,
                'business_id' => $business->id,
                'sort_order' => $n,
            ]);
        }

        $cricket = $this->attachGame($business, 'cricket-nets', 1);

        foreach (range(1, 3) as $n) {
            Spot::factory()->create([
                'business_game_id' => $cricket->id,
                'business_id' => $business->id,
                'name' => "Practice Net {$n}",
                'price_amount' => 1200,
                'price_unit_minutes' => 30,
                'min_duration_minutes' => 30,
                'sort_order' => $n,
            ]);
        }

        return $business;
    }

    private function createPadelClub(User $owner): Business
    {
        $business = $this->makeBusiness($owner, [
            'name' => 'Smash Padel Club',
            'description' => 'Three glass-walled padel courts with coaching available.',
            'address' => '7th Commercial Lane, Zamzama',
            'area' => 'Clifton',
            'contact_number' => '+922135830003',
            'hours' => OperatingHours::everyDay('06:00', '23:00'),
        ]);

        $padel = $this->attachGame($business, 'padel');

        foreach (range(1, 3) as $n) {
            Spot::factory()->padel($n)->create([
                'business_game_id' => $padel->id,
                'business_id' => $business->id,
                'sort_order' => $n,
                // Weekday/weekend pricing showcase: Rs 4,000 on Sat/Sun and
                // public holidays, Rs 2,500 the rest of the week (via
                // padel()'s base price_amount).
                'weekend_price_amount' => 4000,
            ]);
        }

        return $business;
    }

    /**
     * The moderation queue: one awaiting review, one rejected, one suspended,
     * and one flagged as a possible duplicate (SRS 9.11).
     */
    private function createPendingAndProblemBusinesses(User $owner): void
    {
        $pending = Business::factory()->pendingReview()->create([
            'owner_id' => $owner->id,
            'name' => 'PlayZone Family Entertainment',
            'description' => 'Bowling lanes, VR booths and a games arcade.',
            'address' => 'Ground Floor, Dolmen Mall',
            'area' => 'Clifton',
            'contact_number' => '+922135820004',
            'operating_hours' => OperatingHours::everyDay('11:00', '23:00'),
        ]);

        $bowling = $this->attachGame($pending, 'bowling');

        foreach (range(1, 4) as $n) {
            Spot::factory()->create([
                'business_game_id' => $bowling->id,
                'business_id' => $pending->id,
                'name' => "Lane {$n}",
                'price_amount' => 800,
                'price_unit_minutes' => 30,
                'min_duration_minutes' => 30,
                'sort_order' => $n,
            ]);
        }

        Business::factory()->duplicateFlagged()->pendingReview()->create([
            'owner_id' => $owner->id,
            'name' => 'PlayZone Family Entertainment Centre',
            'address' => 'Ground Floor, Dolmen Mall',
            'area' => 'Clifton',
            // Same number as the listing above -- exactly the SRS 9.11 signal.
            'contact_number' => '+922135820004',
            'operating_hours' => OperatingHours::everyDay('11:00', '23:00'),
        ]);

        Business::factory()->rejected('Address could not be verified during the site visit.')->create([
            'owner_id' => $owner->id,
            'name' => 'Corner Pocket Snooker',
            'area' => 'Nazimabad',
            'contact_number' => '+922136610005',
        ]);

        $suspended = Business::factory()->suspended('Repeated unresolved customer complaints.')->create([
            'owner_id' => $owner->id,
            'name' => 'Night Owl Gaming Lounge',
            'area' => 'PECHS',
            'contact_number' => '+922134530006',
            'operating_hours' => OperatingHours::everyDay('18:00', '04:00'),
        ]);

        $xbox = $this->attachGame($suspended, 'xbox');

        Spot::factory()->create([
            'business_game_id' => $xbox->id,
            'business_id' => $suspended->id,
            'name' => 'Xbox Booth 1',
            'price_amount' => 350,
            'price_unit_minutes' => 30,
            'min_duration_minutes' => 60,
        ]);
    }

    /** Reservations covering every state in the SRS section 5 lifecycle. */
    private function createReservations(Business $cueConsole, Business $turfArena, array $customers): void
    {
        $snookerTable = $cueConsole->spots()->where('name', 'Snooker Table 1')->first();
        $snookerTable2 = $cueConsole->spots()->where('name', 'Snooker Table 2')->first();
        $ps5Room = $cueConsole->spots()->where('name', 'PS5 Room A')->first();
        $futsalCourt = $turfArena->spots()->where('name', 'Futsal Court 1')->first();

        $tomorrow = Carbon::tomorrow();
        $today = Carbon::today();

        // Confirmed, upcoming.
        $this->makeReservation($snookerTable, $customers['ali'], $tomorrow->copy()->setTime(20, 0), 60, [
            'status' => ReservationStatus::Confirmed,
            'responded_at' => now()->subHours(3),
        ]);

        // Two customers pending on the SAME slot -- legitimate under the
        // first-approval-wins model, and the case that makes the approval-time
        // lock necessary.
        $contested = $tomorrow->copy()->setTime(21, 0);
        $this->makeReservation($snookerTable, $customers['hina'], $contested, 60);
        $this->makeReservation($snookerTable, $customers['omar'], $contested, 60);

        // A pending request from the repeat no-show customer, so the Owner's
        // approval screen has a warning badge to render.
        $this->makeReservation($ps5Room, $customers['danish'], $tomorrow->copy()->setTime(18, 0), 120);

        // Pending but already past its deadline -- the expiry sweep should
        // catch this on its next run.
        $this->makeReservation($snookerTable2, $customers['ali'], $today->copy()->setTime(23, 0), 60, [
            'response_deadline' => now()->subHours(2),
        ]);

        $this->makeReservation($futsalCourt, $customers['omar'], $tomorrow->copy()->setTime(22, 0), 60, [
            'status' => ReservationStatus::Confirmed,
            'responded_at' => now()->subDay(),
        ]);

        // --- History -------------------------------------------------------

        $this->makeReservation($snookerTable, $customers['ali'], $today->copy()->subDays(3)->setTime(19, 0), 90, [
            'status' => ReservationStatus::Completed,
            'responded_at' => now()->subDays(4),
        ]);

        $this->makeReservation($futsalCourt, $customers['hina'], $today->copy()->subDays(5)->setTime(20, 0), 60, [
            'status' => ReservationStatus::Completed,
            'responded_at' => now()->subDays(6),
        ]);

        $this->makeReservation($ps5Room, $customers['danish'], $today->copy()->subDays(2)->setTime(17, 0), 60, [
            'status' => ReservationStatus::NoShow,
            'responded_at' => now()->subDays(3),
            'no_show_flagged_at' => now()->subDays(2),
        ]);

        $this->makeReservation($snookerTable2, $customers['omar'], $today->copy()->subDays(4)->setTime(20, 0), 60, [
            'status' => ReservationStatus::Rejected,
            'responded_at' => now()->subDays(5),
            'rejection_reason_code' => RejectionReason::FullyBooked,
            'rejection_reason_text' => 'Private tournament that evening.',
        ]);

        $this->makeReservation($futsalCourt, $customers['ali'], $today->copy()->subDays(6)->setTime(21, 0), 60, [
            'status' => ReservationStatus::Expired,
        ]);

        $this->makeReservation($snookerTable, $customers['hina'], $today->copy()->subDays(7)->setTime(18, 0), 60, [
            'status' => ReservationStatus::Cancelled,
            'cancelled_at' => now()->subDays(7),
            'cancelled_by' => $customers['hina']->id,
            'cancelled_by_role' => UserRole::User,
            'is_late_cancellation' => true,
        ]);
    }

    /**
     * A block placed over a live booking, and the unresolved conflict it raises.
     *
     * Per SRS 9.6 the booking is NOT silently cancelled -- the Owner has to
     * settle it with the customer, which is what the conflict queue is for.
     */
    private function createBlocksAndConflicts(Business $cueConsole, array $customers): void
    {
        $table = $cueConsole->spots()->where('name', 'Snooker Table 3')->first();
        $start = Carbon::tomorrow()->setTime(19, 0);

        $reservation = $this->makeReservation($table, $customers['ali'], $start, 60, [
            'status' => ReservationStatus::Confirmed,
            'responded_at' => now()->subDay(),
        ]);

        $block = SpotBlock::create([
            'spot_id' => $table->id,
            'start_datetime' => $start->copy()->subMinutes(30),
            'end_datetime' => $start->copy()->addHours(3),
            'reason' => 'Table re-clothing',
            'created_by' => $cueConsole->owner_id,
            'created_by_role' => UserRole::Owner->value,
        ]);

        ReservationConflict::create([
            'reservation_id' => $reservation->id,
            'source_type' => ConflictSource::SpotBlock,
            'source_id' => $block->id,
            'raised_by' => $cueConsole->owner_id,
        ]);

        // A clean upcoming block with nothing booked under it.
        SpotBlock::create([
            'spot_id' => $cueConsole->spots()->where('name', 'Snooker Table 4')->first()->id,
            'start_datetime' => Carbon::tomorrow()->addDay()->setTime(15, 0),
            'end_datetime' => Carbon::tomorrow()->addDay()->setTime(18, 0),
            'reason' => 'Private event',
            'created_by' => $cueConsole->owner_id,
            'created_by_role' => UserRole::Owner->value,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function makeBusiness(User $owner, array $attributes): Business
    {
        return Business::factory()->active()->create([
            'owner_id' => $owner->id,
            'name' => $attributes['name'],
            'description' => $attributes['description'],
            'address' => $attributes['address'],
            'area' => $attributes['area'],
            'contact_number' => $attributes['contact_number'],
            'operating_hours' => $attributes['hours'],
        ]);
    }

    private function attachGame(Business $business, string $gameSlug, int $sortOrder = 0): BusinessGame
    {
        return BusinessGame::create([
            'business_id' => $business->id,
            'game_id' => Game::where('slug', $gameSlug)->firstOrFail()->id,
            'sort_order' => $sortOrder,
        ]);
    }

    private function makeReservation(
        Spot $spot,
        User $user,
        Carbon $start,
        int $durationMinutes,
        array $overrides = [],
    ): Reservation {
        return Reservation::create(array_merge([
            'spot_id' => $spot->id,
            'user_id' => $user->id,
            'business_id' => $spot->business_id,
            'start_datetime' => $start,
            'end_datetime' => $start->copy()->addMinutes($durationMinutes),
            'duration_minutes' => $durationMinutes,
            // Snapshotted at booking time so a later price change cannot
            // rewrite what the customer agreed to (SRS 9.10).
            'price_amount_snapshot' => $spot->price_amount,
            'price_unit_minutes_snapshot' => $spot->price_unit_minutes,
            'total_price' => $spot->priceFor($durationMinutes),
            'status' => ReservationStatus::Pending,
            'response_deadline' => $this->deadlines->for($start),
            'requested_at' => now()->subHours(4),
        ], $overrides));
    }
}
