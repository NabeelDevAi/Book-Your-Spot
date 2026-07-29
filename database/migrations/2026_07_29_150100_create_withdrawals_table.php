<?php

use App\Enums\WithdrawalStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An Owner cashing out. The only route by which money leaves the platform.
     */
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();

            // Quoted in support conversations and on the bank transfer itself,
            // so an Admin can tie a line on a statement back to a request.
            $table->string('reference', 16)->unique();

            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            $table->unsignedBigInteger('amount_minor');

            $table->string('status', 20)->default(WithdrawalStatus::Requested->value);

            // The bank details AS THEY WERE when the money was requested.
            //
            // Same rule the price snapshot follows (SRS 9.10): an Owner editing
            // their payout account afterwards must not rewrite the record of
            // where a completed transfer actually went. Without this, "you paid
            // it to the wrong account" is unanswerable.
            $table->json('payout_account_snapshot');

            $table->timestamp('requested_at');

            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();

            // The bank's own transfer reference, recorded at settlement.
            $table->string('external_reference')->nullable();

            $table->text('failure_reason')->nullable();

            $table->timestamps();

            // The Admin queue: oldest open request first.
            $table->index(['status', 'requested_at']);
            // The Owner's own history.
            $table->index(['owner_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
