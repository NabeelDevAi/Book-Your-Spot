<?php

namespace Tests\Unit;

use App\Enums\CancellationEvent;
use App\Models\Reservation;
use App\Services\Booking\RefundResolver;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The refund split, in isolation.
 *
 * The property that matters more than any individual figure:
 * user + owner === amount_paid_minor, exactly, in every branch. If that ever
 * fails, the ledger gains or loses money on a cancellation and no amount of
 * correct-looking percentages will save it.
 *
 * No database here -- an unsaved model carries everything the resolver reads,
 * which keeps the whole matrix cheap enough to enumerate exhaustively. The
 * framework TestCase is still needed: Eloquent's datetime cast resolves a
 * connection for its date format, even on a model that is never saved.
 */
class RefundResolverTest extends TestCase
{
    private const TIERS = [
        ['min_hours_before' => 24, 'refund_percent' => 100],
        ['min_hours_before' => 2, 'refund_percent' => 50],
        ['min_hours_before' => 0, 'refund_percent' => 0],
    ];

    private function reservation(int $paidMinor, float $hoursUntilStart): Reservation
    {
        $reservation = new Reservation;

        $reservation->amount_paid_minor = $paidMinor;
        $reservation->refund_policy_snapshot = self::TIERS;
        $reservation->start_datetime = Carbon::parse('2026-08-02 19:00')->addMinutes((int) round($hoursUntilStart * 60));

        return $reservation;
    }

    private function resolver(): RefundResolver
    {
        return new RefundResolver;
    }

    private function now(): Carbon
    {
        return Carbon::parse('2026-08-02 19:00');
    }

    /*
    |--------------------------------------------------------------------------
    | Customer cancellations follow the tiers
    |--------------------------------------------------------------------------
    */

    /** @return array<string, array{0: float, 1: int}> */
    public static function customerTiers(): array
    {
        return [
            'a week out' => [168.0, 100],
            'well clear of the first tier' => [48.0, 100],
            'exactly on the 24h boundary' => [24.0, 100],
            'just inside 24h' => [23.9, 50],
            'mid window' => [6.0, 50],
            'exactly on the 2h boundary' => [2.0, 50],
            'just inside 2h' => [1.9, 0],
            'half an hour out' => [0.5, 0],
            'at the start time' => [0.0, 0],
            'after it has begun' => [-1.0, 0],
        ];
    }

    #[Test]
    #[DataProvider('customerTiers')]
    public function a_customer_cancellation_follows_the_snapshotted_tiers(float $hours, int $expectedPercent): void
    {
        $reservation = $this->reservation(60_000, $hours);

        $split = $this->resolver()->resolve($reservation, CancellationEvent::CustomerCancelled, $this->now());

        $this->assertSame($expectedPercent, $split['refund_percent']);
        $this->assertSame(60_000 * $expectedPercent / 100, $split['user_minor']);
    }

    /*
    |--------------------------------------------------------------------------
    | The asymmetry
    |--------------------------------------------------------------------------
    */

    /**
     * An Owner must never be able to cancel a paid booking at no cost. If they
     * could, "confirmed" would mean nothing -- they could take a better
     * walk-in at 7:55pm and hand the money back with a shrug.
     */
    #[Test]
    public function an_owner_cancellation_always_refunds_in_full_however_late(): void
    {
        foreach ([168.0, 24.0, 2.0, 0.25, 0.0] as $hours) {
            $split = $this->resolver()->resolve(
                $this->reservation(60_000, $hours),
                CancellationEvent::OwnerCancelled,
                $this->now(),
            );

            $this->assertSame(100, $split['refund_percent'], "Failed at {$hours}h before start");
            $this->assertSame(60_000, $split['user_minor']);
            $this->assertSame(0, $split['owner_minor']);
        }
    }

    #[Test]
    public function an_admin_cancellation_always_refunds_in_full(): void
    {
        $split = $this->resolver()->resolve(
            $this->reservation(60_000, 0.25),
            CancellationEvent::AdminCancelled,
            $this->now(),
        );

        $this->assertSame(60_000, $split['user_minor']);
        $this->assertSame(0, $split['owner_minor']);
    }

    /** The venue held the slot and turned other customers away. */
    #[Test]
    public function a_no_show_pays_the_owner_in_full(): void
    {
        $split = $this->resolver()->resolve(
            $this->reservation(60_000, -2.0),
            CancellationEvent::NoShow,
            $this->now(),
        );

        $this->assertSame(0, $split['user_minor']);
        $this->assertSame(60_000, $split['owner_minor']);
    }

    /*
    |--------------------------------------------------------------------------
    | The invariant
    |--------------------------------------------------------------------------
    */

    /**
     * Exhaustive over every event, every tier boundary, and a set of amounts
     * chosen to be awkward for integer division -- primes, odd paisa, and
     * values where 50% lands on a half-paisa.
     */
    #[Test]
    public function the_split_always_reconstructs_the_amount_paid(): void
    {
        $amounts = [1, 3, 7, 99, 101, 12_345, 60_001, 999_999, 5_000_000];
        $hours = [168.0, 24.0, 23.9, 6.0, 2.0, 1.9, 0.0, -3.0];
        $checked = 0;

        foreach (CancellationEvent::cases() as $event) {
            foreach ($amounts as $paid) {
                foreach ($hours as $h) {
                    $split = $this->resolver()->resolve($this->reservation($paid, $h), $event, $this->now());

                    $this->assertSame(
                        $paid,
                        $split['user_minor'] + $split['owner_minor'],
                        "Money leaked: {$event->value}, {$paid} paisa, {$h}h before start",
                    );

                    $this->assertGreaterThanOrEqual(0, $split['user_minor']);
                    $this->assertGreaterThanOrEqual(0, $split['owner_minor']);
                    $checked++;
                }
            }
        }

        $this->assertSame(288, $checked, 'The matrix should be exhaustive.');
    }

    /**
     * Rounding goes to the customer. On an odd amount at 50%, the stray paisa
     * must land somewhere deterministic rather than vanishing.
     */
    #[Test]
    public function the_remainder_goes_to_the_customer(): void
    {
        $split = $this->resolver()->resolve(
            $this->reservation(101, 6.0),
            CancellationEvent::CustomerCancelled,
            $this->now(),
        );

        $this->assertSame(50, $split['user_minor']);
        $this->assertSame(51, $split['owner_minor']);
        $this->assertSame(101, $split['user_minor'] + $split['owner_minor']);
    }

    /*
    |--------------------------------------------------------------------------
    | Snapshot behaviour
    |--------------------------------------------------------------------------
    */

    /**
     * The reservation's own policy wins over anything configured later. This is
     * the whole point of snapshotting: a dispute is argued against the terms
     * the customer agreed to.
     */
    #[Test]
    public function it_uses_the_reservations_snapshot_not_the_live_config(): void
    {
        $reservation = $this->reservation(60_000, 6.0);
        $reservation->refund_policy_snapshot = [
            ['min_hours_before' => 1, 'refund_percent' => 100],
        ];

        $split = $this->resolver()->resolve($reservation, CancellationEvent::CustomerCancelled, $this->now());

        $this->assertSame(100, $split['refund_percent']);
    }

    /** A misconfigured policy with no zero-hour floor must not become a refund exploit. */
    #[Test]
    public function an_unmatched_policy_forfeits_rather_than_guessing(): void
    {
        $reservation = $this->reservation(60_000, 0.25);
        $reservation->refund_policy_snapshot = [
            ['min_hours_before' => 48, 'refund_percent' => 100],
        ];

        $split = $this->resolver()->resolve($reservation, CancellationEvent::CustomerCancelled, $this->now());

        $this->assertSame(0, $split['user_minor']);
        $this->assertSame(60_000, $split['owner_minor']);
    }

    #[Test]
    public function an_unpaid_reservation_settles_to_nothing(): void
    {
        $reservation = $this->reservation(0, 6.0);
        $reservation->amount_paid_minor = null;

        $split = $this->resolver()->resolve($reservation, CancellationEvent::CustomerCancelled, $this->now());

        $this->assertSame(0, $split['user_minor']);
        $this->assertSame(0, $split['owner_minor']);
    }
}
