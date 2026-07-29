<?php

use App\Enums\HoldStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Funds reserved against a pending reservation.
     *
     * Holds are deliberately NOT ledger rows. Nothing has moved yet -- the
     * money is still the customer's, merely spoken for -- and recording
     * non-movements in the ledger would make it impossible to sum.
     *
     * This table is what makes the existing booking flow work unchanged.
     * `ReservationStatus::blocking()` excludes `pending`, so several customers
     * may queue on one slot; a hold is free to place and free to release, so
     * each of them can reserve their own funds and the losers are let go the
     * moment `autoRejectCompeting()` rejects them. A card payment could not do
     * this -- refunding three of four customers costs real money and days.
     */
    public function up(): void
    {
        Schema::create('wallet_holds', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();

            // UNIQUE, not merely indexed. One reservation holds funds exactly
            // once in its life, whatever happens to it afterwards, which makes
            // a double-hold structurally impossible rather than just unlikely.
            $table->foreignId('reservation_id')->unique()->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('amount_minor');

            $table->string('status', 20)->default(HoldStatus::Active->value);

            // Why the funds were let go: rejected, expired, cancelled,
            // slot_taken, admin. Worth keeping -- "my money came back and I do
            // not know why" is a support ticket either way, and this answers it.
            $table->string('released_reason', 40)->nullable();

            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            // The available-balance calculation, and the orphaned-hold sweep.
            $table->index(['wallet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_holds');
    }
};
