<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Moderation trail (NFR-6, SRS 9.20).
     *
     * Every Admin override -- editing someone else's listing, suspending a
     * business or user, force-resolving a reservation -- lands here with the
     * actor, timestamp and reason. This exists specifically to settle
     * "who changed what" disputes after the fact.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Nullable so system-initiated actions (scheduled expiry, auto-reject
            // on approval) can be logged with no human actor.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role', 20)->nullable();

            $table->string('action', 80);
            $table->string('target_type', 80)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();

            $table->text('reason')->nullable();
            $table->json('meta')->nullable();

            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['target_type', 'target_id', 'created_at']);
            $table->index(['actor_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
