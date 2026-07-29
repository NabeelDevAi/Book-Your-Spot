<?php

use App\Enums\TopupStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per attempt to put money into a wallet.
     *
     * The row is created BEFORE the gateway is contacted, so a charge can never
     * exist that there is no local record of -- that is the one outcome which
     * cannot be reconciled afterwards. The amount is recorded here at creation
     * and checked against whatever the gateway reports, rather than trusting a
     * figure that arrived over the wire: a client that can nominate the amount
     * it was credited is a client that credits itself.
     *
     * Payments are currently SIMULATED (see SimulatedGateway) -- top-ups settle
     * instantly and nothing is really charged. The columns are named after no
     * particular provider so that introducing a real one needs no migration.
     */
    public function up(): void
    {
        Schema::create('topups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('amount_minor');

            $table->string('status', 20)->default(TopupStatus::Pending->value);

            // Which provider produced this row. Once a real gateway exists,
            // simulated history stays distinguishable from real money.
            $table->string('gateway', 30)->default('simulated');

            // UNIQUE: a top-up is looked up by this and nothing else, and two
            // rows sharing a reference would make "which wallet gets the money"
            // ambiguous at exactly the wrong moment.
            //
            // Nullable because the row is written BEFORE the gateway is called,
            // so an attempt that dies mid-call still leaves a record. MySQL
            // permits repeated NULLs under a unique index, so the guarantee
            // still holds for every row that has a reference.
            $table->string('gateway_reference')->nullable()->unique();
            $table->string('gateway_charge_id')->nullable();

            $table->string('failure_code', 60)->nullable();
            $table->text('failure_message')->nullable();

            $table->timestamp('succeeded_at')->nullable();

            $table->timestamps();

            // The customer's top-up history.
            $table->index(['user_id', 'created_at']);
            // The reconcile sweep: pending rows older than a few minutes.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topups');
    }
};
