<?php

namespace App\Services\Booking;

use App\Enums\ConflictResolution;
use App\Enums\ConflictSource;
use App\Enums\ConflictStatus;
use App\Models\Reservation;
use App\Models\ReservationConflict;
use App\Models\Spot;
use App\Models\SpotBlock;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single mechanism behind SRS cases 9.6, 9.8 and 9.9, which the SRS
 * describes inconsistently (9.6 says never auto-cancel, 9.8 says auto-cancel,
 * 9.9 says "same as 9.8").
 *
 * Resolved as: every disruptive event raises a conflict, and the source decides
 * whether it auto-cancels. Owner-caused disruptions (blocking a spot,
 * deactivating one) stay OPEN for the Owner to settle with the customer --
 * 9.6's reasoning wins because silently cancelling a confirmed booking is the
 * trust-breaking failure the SRS itself names, and the Owner created the clash.
 * A business suspension auto-cancels, because the venue genuinely cannot honour
 * the booking and the suspended Owner cannot be relied on to resolve anything.
 */
class ConflictService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ReservationService $reservations,
    ) {}

    /**
     * Reservations a new block would clash with. Called before creating the
     * block so the Owner can be warned rather than surprised.
     */
    public function reservationsClashingWithBlock(Spot $spot, Carbon $start, Carbon $end): Collection
    {
        return Reservation::query()
            ->forSpot($spot->id)
            ->open()
            ->overlapping($start, $end)
            ->with('user')
            ->orderBy('start_datetime')
            ->get();
    }

    /**
     * Raise conflicts for every live booking a new block sits on top of.
     *
     * The bookings are NOT cancelled. The Owner has to contact each customer
     * and record what was agreed (SRS 9.6).
     *
     * @return int  number of conflicts raised
     */
    public function raiseForBlock(SpotBlock $block, ?User $actor = null): int
    {
        $clashing = $this->reservationsClashingWithBlock(
            $block->spot,
            $block->start_datetime,
            $block->end_datetime,
        );

        foreach ($clashing as $reservation) {
            $this->raise($reservation, ConflictSource::SpotBlock, $block->id, $actor);
        }

        return $clashing->count();
    }

    /**
     * Raise conflicts for future bookings on a spot being taken out of service
     * (SRS 9.9). Same hold-for-the-Owner policy as a block.
     *
     * @return int  number of conflicts raised
     */
    public function raiseForSpotDeactivation(Spot $spot, ?User $actor = null): int
    {
        $affected = $spot->futureOpenReservations()->get();

        foreach ($affected as $reservation) {
            $this->raise($reservation, ConflictSource::SpotDeactivation, $spot->id, $actor);
        }

        return $affected->count();
    }

    /**
     * Create one conflict, unless an identical open one already exists.
     *
     * The idempotency matters: an Owner who blocks a spot, withdraws the block
     * and blocks it again should not accumulate three open conflicts against
     * the same booking.
     */
    public function raise(
        Reservation $reservation,
        ConflictSource $source,
        ?int $sourceId = null,
        ?User $actor = null,
    ): ?ReservationConflict {
        $existing = $reservation->conflicts()
            ->where('status', ConflictStatus::Open)
            ->where('source_type', $source)
            ->where('source_id', $sourceId)
            ->first();

        if ($existing) {
            return $existing;
        }

        $conflict = ReservationConflict::create([
            'reservation_id' => $reservation->id,
            'source_type' => $source,
            'source_id' => $sourceId,
            'raised_by' => $actor?->id,
        ]);

        $this->audit->log(
            AuditLogger::CONFLICT_RAISED,
            $conflict,
            meta: [
                'reservation' => $reservation->reference,
                'source' => $source->value,
            ],
            actor: $actor,
        );

        return $conflict;
    }

    /**
     * Close a conflict, applying whatever the Owner agreed with the customer.
     *
     * Cancelling here routes through the state machine so the reservation's own
     * cancellation rules, notifications and audit entry all apply -- the
     * conflict queue must not become a second, quieter way to cancel a booking.
     */
    public function resolve(
        ReservationConflict $conflict,
        ConflictResolution $resolution,
        User $actor,
        ?string $note = null,
    ): void {
        DB::transaction(function () use ($conflict, $resolution, $actor, $note) {
            if ($resolution === ConflictResolution::Cancelled) {
                $this->reservations->cancel(
                    $conflict->reservation,
                    $actor,
                    $note ?: 'Cancelled while resolving a scheduling clash.',
                );
            }

            $conflict->forceFill([
                'status' => ConflictStatus::Resolved,
                'resolution' => $resolution,
                'resolution_note' => $note,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ])->save();

            $this->audit->log(
                AuditLogger::CONFLICT_RESOLVED,
                $conflict,
                $note,
                ['resolution' => $resolution->value],
                $actor,
            );
        });
    }

    /**
     * Withdrawing a block should retire the conflicts it raised, but only those
     * still open -- an Owner who already cancelled the booking should not have
     * that decision quietly rewritten.
     */
    public function retireForBlock(SpotBlock $block, User $actor): void
    {
        ReservationConflict::query()
            ->where('source_type', ConflictSource::SpotBlock)
            ->where('source_id', $block->id)
            ->where('status', ConflictStatus::Open)
            ->get()
            ->each(function (ReservationConflict $conflict) use ($actor) {
                $conflict->forceFill([
                    'status' => ConflictStatus::Resolved,
                    'resolution' => ConflictResolution::Kept,
                    'resolution_note' => 'The block was removed, so the booking stands.',
                    'resolved_by' => $actor->id,
                    'resolved_at' => now(),
                ])->save();

                $this->audit->log(
                    AuditLogger::CONFLICT_RESOLVED,
                    $conflict,
                    'Block withdrawn',
                    ['resolution' => ConflictResolution::Kept->value],
                    $actor,
                );
            });
    }
}
