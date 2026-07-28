<?php

use App\Enums\BusinessStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->string('address');
            // V1 is single-city (SRS 2.4) but the column exists for future
            // multi-city support; it is simply not exposed as a filter yet.
            $table->string('city')->default('Karachi');
            $table->string('area');
            $table->string('contact_number', 32);

            $table->string('status', 20)->default(BusinessStatus::PendingReview->value);
            $table->text('rejection_reason')->nullable();
            $table->text('suspension_reason')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            // SRS 9.11: a repeated phone number or address is a signal for either
            // an accidental duplicate or a bad-faith listing. Flagged for Admin
            // review rather than blocked, since one owner may genuinely run two
            // venues from adjacent units.
            $table->boolean('duplicate_flagged')->default(false);
            $table->text('duplicate_note')->nullable();

            $table->json('operating_hours')->nullable();

            $table->timestamps();
            // Never hard-deleted once it has any history (SRS 9.9 principle).
            $table->softDeletes();

            // NFR-3: the public search filters on status + locality.
            $table->index(['status', 'city', 'area']);
            $table->index(['owner_id', 'status']);
            $table->index('duplicate_flagged');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
