<?php

use App\Enums\ReservationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();

            // Human-readable code the customer quotes at the desk. Payment is
            // collected in person, so the Owner needs a short handle to match a
            // walk-in against a booking. Not in the SRS entity list, but the
            // pay-at-venue model does not really work without it.
            $table->string('reference', 16)->unique();

            $table->foreignId('spot_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Denormalised: every Owner and Admin listing filters by venue, and
            // reaching it means spots -> business_games -> businesses otherwise.
            $table->foreignId('business_id')->constrained()->restrictOnDelete();

            // Local wall-clock datetimes -- see the note in spot_blocks.
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');
            $table->unsignedSmallInteger('duration_minutes');

            // SRS 9.10: a later price change must not rewrite history, so the
            // rate in force at booking time is copied onto the record rather
            // than referenced live from the Spot.
            $table->decimal('price_amount_snapshot', 10, 2);
            $table->unsignedSmallInteger('price_unit_minutes_snapshot');
            $table->decimal('total_price', 10, 2);

            $table->string('status', 20)->default(ReservationStatus::Pending->value);

            $table->text('customer_note')->nullable();

            // When the pending request auto-expires if the Owner stays silent.
            // Computed at creation: start - 12h, or for late bookings
            // min(now + 2h, start - 1h). See config/booking.php.
            $table->dateTime('response_deadline');

            $table->timestamp('requested_at');
            $table->timestamp('responded_at')->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('rejection_reason_code', 40)->nullable();
            $table->text('rejection_reason_text')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancelled_by_role', 20)->nullable();
            $table->text('cancellation_reason')->nullable();
            // SRS 9.3: distinguish an on-time cancellation from one inside the
            // cutoff. No financial penalty is possible in V1, so visibility is
            // the entire remedy.
            $table->boolean('is_late_cancellation')->default(false);

            $table->timestamp('no_show_flagged_at')->nullable();
            $table->foreignId('no_show_flagged_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('reminder_sent_at')->nullable();

            $table->timestamps();

            // Overlap check on approval, and the availability calculation.
            $table->index(['spot_id', 'status', 'start_datetime', 'end_datetime']);
            // The expiry sweep.
            $table->index(['status', 'response_deadline']);
            // Owner reservation queues.
            $table->index(['business_id', 'status', 'start_datetime']);
            // User booking history.
            $table->index(['user_id', 'status', 'start_datetime']);
            // The auto-complete and reminder sweeps.
            $table->index(['status', 'end_datetime']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
