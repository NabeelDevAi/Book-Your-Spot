<?php

namespace App\Services\Admin;

use App\Enums\BusinessStatus;
use App\Enums\ConflictResolution;
use App\Enums\ConflictSource;
use App\Enums\ConflictStatus;
use App\Enums\RejectionReason;
use App\Enums\ReservationStatus;
use App\Models\Business;
use App\Models\Reservation;
use App\Models\User;
use App\Notifications\BusinessApproved;
use App\Notifications\BusinessReinstated;
use App\Notifications\BusinessRejected;
use App\Notifications\BusinessSuspended;
use App\Notifications\ReservationRejected;
use App\Services\AuditLogger;
use App\Services\Booking\ReservationService;
use Illuminate\Support\Facades\DB;

/**
 * FR-3.1 / FR-3.2 -- Admin control over a venue's lifecycle.
 *
 * The interesting part is suspension. SRS 9.8 requires that future bookings at
 * a suspended venue are not left silently orphaned: every one is resolved and
 * the customer is told why. This is the one ConflictSource that auto-cancels --
 * unlike an owner blocking their own spot, the venue genuinely cannot honour
 * the booking, and a suspended Owner cannot be relied on to sort it out.
 */
class BusinessModerationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ReservationService $reservations,
    ) {}

    public function approve(Business $business, User $admin): Business
    {
        DB::transaction(function () use ($business, $admin) {
            $business->forceFill([
                'status' => BusinessStatus::Active,
                'rejection_reason' => null,
                'suspension_reason' => null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                // Approving is an explicit judgement that this is not a
                // duplicate, so the flag is cleared rather than left nagging.
                'duplicate_flagged' => false,
                'duplicate_note' => null,
            ])->save();

            $this->audit->log(AuditLogger::BUSINESS_APPROVED, $business, actor: $admin);
        });

        $business->loadMissing('owner')->owner->notify(new BusinessApproved($business));

        return $business;
    }

    public function reject(Business $business, User $admin, string $reason): Business
    {
        DB::transaction(function () use ($business, $admin, $reason) {
            $business->forceFill([
                'status' => BusinessStatus::Rejected,
                'rejection_reason' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            $this->audit->log(AuditLogger::BUSINESS_REJECTED, $business, $reason, actor: $admin);
        });

        $business->loadMissing('owner')->owner->notify(new BusinessRejected($business, $reason));

        return $business;
    }

    /**
     * SRS 9.8 -- suspend, and deal properly with everything already booked.
     *
     * Pending requests are rejected (nobody was promised anything yet) while
     * confirmed bookings are cancelled (a promise is being broken, so it routes
     * through the state machine and gets the full cancellation treatment).
     * Both leave a resolved conflict behind so the trail is complete.
     *
     * @return int  bookings affected
     */
    public function suspend(Business $business, User $admin, string $reason): int
    {
        $affected = DB::transaction(function () use ($business, $admin, $reason) {
            $business->forceFill([
                'status' => BusinessStatus::Suspended,
                'suspension_reason' => $reason,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            $this->audit->log(AuditLogger::BUSINESS_SUSPENDED, $business, $reason, actor: $admin);

            return $this->releaseFutureBookings($business, $admin, $reason);
        });

        $business->loadMissing('owner')->owner->notify(new BusinessSuspended($business, $reason, $affected));

        return $affected;
    }

    /**
     * Cancel or reject every future booking, recording a conflict for each.
     *
     * Deliberately does NOT touch bookings already in the past: those happened,
     * and rewriting completed history to explain a suspension today would be
     * dishonest bookkeeping.
     */
    private function releaseFutureBookings(Business $business, User $admin, string $reason): int
    {
        $affected = Reservation::query()
            ->where('business_id', $business->id)
            ->whereIn('status', ReservationStatus::openValues())
            ->where('start_datetime', '>', now())
            ->with(['user', 'spot', 'business'])
            ->get();

        foreach ($affected as $reservation) {
            $conflict = $reservation->conflicts()->create([
                'source_type' => ConflictSource::BusinessSuspension,
                'source_id' => $business->id,
                'raised_by' => $admin->id,
            ]);

            if ($reservation->status === ReservationStatus::Pending) {
                // Nothing was promised, so this is a decline rather than a
                // broken commitment -- and it frees the customer to look
                // elsewhere immediately.
                $reservation->forceFill([
                    'status' => ReservationStatus::Rejected,
                    'responded_at' => now(),
                    'responded_by' => $admin->id,
                    'rejection_reason_code' => RejectionReason::VenueUnavailable,
                    'rejection_reason_text' => 'This venue is temporarily unavailable.',
                ])->save();

                $this->audit->log(
                    AuditLogger::RESERVATION_REJECTED,
                    $reservation,
                    'Venue suspended',
                    ['reference' => $reservation->reference],
                    $admin,
                );

                $reservation->user->notify(new ReservationRejected($reservation));
            } else {
                // Confirmed: a real promise is being broken, so it goes through
                // the state machine and gets the standard cancellation
                // notification and audit entry.
                $this->reservations->cancel(
                    $reservation,
                    $admin,
                    'This venue is temporarily unavailable.',
                );
            }

            $conflict->forceFill([
                'status' => ConflictStatus::Resolved,
                'resolution' => ConflictResolution::Cancelled,
                'resolution_note' => "Venue suspended: {$reason}",
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
            ])->save();
        }

        return $affected->count();
    }

    /**
     * Lift a suspension.
     *
     * Cancelled bookings are NOT restored: the customers were told it was off
     * and have made other plans, and silently reinstating a booking someone
     * believes is cancelled is worse than the cancellation itself.
     */
    public function reinstate(Business $business, User $admin): Business
    {
        DB::transaction(function () use ($business, $admin) {
            $business->forceFill([
                'status' => BusinessStatus::Active,
                'suspension_reason' => null,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ])->save();

            $this->audit->log(AuditLogger::BUSINESS_REINSTATED, $business, actor: $admin);
        });

        $business->loadMissing('owner')->owner->notify(new BusinessReinstated($business));

        return $business;
    }
}
