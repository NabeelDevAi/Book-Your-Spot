<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where an Owner's money is sent.
     *
     * Deliberately plain bank fields rather than a gateway token. The outbound
     * leg is a manual transfer over local rails (Raast / IBFT), so what an
     * Admin needs is exactly what they would type into a banking app.
     */
    public function up(): void
    {
        Schema::create('payout_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();

            $table->string('bank_name');
            $table->string('account_title');
            $table->string('account_number', 64);
            $table->string('iban', 34)->nullable();

            // Set once an Admin has confirmed a transfer actually landed.
            // Unverified accounts can still be used -- the first withdrawal is
            // how they get verified -- but the queue flags them.
            $table->timestamp('verified_at')->nullable();

            $table->timestamps();

            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_accounts');
    }
};
