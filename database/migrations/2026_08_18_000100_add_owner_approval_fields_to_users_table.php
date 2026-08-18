<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner-account approval gate.
     *
     * Reuses the existing `status` column (a new Owner registers as
     * `pending_approval` instead of `active`; see UserStatus). These two
     * columns record the Admin decision, mirroring `businesses.rejection_reason`
     * / `reviewed_by` / `reviewed_at` rather than inventing a new shape.
     *
     * Existing accounts are untouched: `status` already defaults to `active`
     * and nothing here rewrites existing rows, so every Owner who registered
     * before this shipped stays exactly as usable as they were yesterday.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('suspension_reason');
            $table->foreignId('reviewed_by')->nullable()->after('suspended_by')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropColumn(['rejection_reason', 'reviewed_by', 'reviewed_at']);
        });
    }
};
