<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // One account holds exactly one role (SRS 9.14). Owners cannot book;
            // an owner who wants to play elsewhere registers a separate account.
            $table->string('role', 20)->default(UserRole::User->value)->after('email');

            $table->string('phone', 32)->nullable()->unique()->after('role');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');

            $table->string('status', 20)->default(UserStatus::Active->value)->after('phone');
            $table->text('suspension_reason')->nullable()->after('status');
            $table->timestamp('suspended_at')->nullable()->after('suspension_reason');
            $table->foreignId('suspended_by')->nullable()->after('suspended_at')
                ->constrained('users')->nullOnDelete();

            // Denormalised counter for SRS 9.12: without payments, a visible
            // no-show history is the only deterrent available, and the Owner
            // needs it at approval time without an aggregate query per request.
            $table->unsignedInteger('no_show_count')->default(0)->after('suspended_by');

            // V1 sends no email, so password reset is admin-mediated: the Admin
            // issues a temporary password and this flag forces a change at login.
            $table->boolean('must_change_password')->default(false)->after('no_show_count');

            $table->index(['role', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['suspended_by']);
            $table->dropIndex(['role', 'status']);
            $table->dropColumn([
                'role', 'phone', 'phone_verified_at', 'status', 'suspension_reason',
                'suspended_at', 'suspended_by', 'no_show_count', 'must_change_password',
            ]);
        });
    }
};
