<?php

use App\Enums\PasswordResetStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces Laravel's emailed reset link for V1 (see FR-1.5 amendment).
     * A user asks for help, an Admin sees the request and issues a temporary
     * password. Laravel's own `password_reset_tokens` table stays in place so
     * the standard flow can be switched back on once email exists.
     */
    public function up(): void
    {
        Schema::create('password_reset_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Captured separately from the user record so we can see what the
            // person actually typed, including a typo'd address.
            $table->string('submitted_email');

            $table->string('status', 20)->default(PasswordResetStatus::Open->value);
            $table->text('note')->nullable();

            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_requests');
    }
};
