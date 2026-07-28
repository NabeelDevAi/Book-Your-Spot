<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner-declared downtime on a Spot: private event, maintenance, personal
     * use (SRS FR-2.9). Kept separate from reservations so blocked time never
     * pollutes booking statistics or looks like a customer to the Owner.
     *
     * A block renders as unavailable to Users, exactly like a confirmed booking.
     */
    public function up(): void
    {
        Schema::create('spot_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spot_id')->constrained()->cascadeOnDelete();

            // DATETIME, not TIMESTAMP: V1 stores local Asia/Karachi wall-clock
            // time so overlap arithmetic needs no timezone conversion, and
            // TIMESTAMP's 2038 ceiling and implicit UTC conversion are both
            // liabilities here.
            $table->dateTime('start_datetime');
            $table->dateTime('end_datetime');

            $table->string('reason')->nullable();

            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('created_by_role', 20);

            $table->timestamps();

            // Drives the overlap lookup on every availability calculation.
            $table->index(['spot_id', 'start_datetime', 'end_datetime']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spot_blocks');
    }
};
