<?php

use App\Enums\ConflictStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reconciles the SRS's contradictory guidance on disrupted bookings.
     *
     * Case 9.6 (owner blocks a spot over live bookings) says do NOT auto-cancel
     * and flag for manual resolution; case 9.8 (admin suspends a business) says
     * DO auto-cancel; case 9.9 says spot deactivation follows case 8.
     *
     * One table, one lifecycle, policy varying by source: owner-caused clashes
     * open a conflict the Owner must resolve by contacting the customer, while
     * a business suspension cancels immediately because the venue genuinely
     * cannot honour the booking and the suspended Owner cannot be relied on to
     * sort it out.
     */
    public function up(): void
    {
        Schema::create('reservation_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();

            $table->string('source_type', 40);
            // Nullable: a business suspension has no single row to point at.
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('status', 20)->default(ConflictStatus::Open->value);
            $table->string('resolution', 40)->nullable();
            $table->text('resolution_note')->nullable();

            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            // The Owner's "needs your attention" queue.
            $table->index(['status', 'created_at']);
            $table->index(['reservation_id', 'status']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_conflicts');
    }
};
