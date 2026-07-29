<?php

use App\Enums\WalletStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One wallet per account, both roles.
     *
     * The balances are cached columns rather than sums over the ledger, and
     * that is the whole point of this table: a derived balance has no row to
     * lock, so two concurrent spends both read the same figure and both
     * succeed. Every mutation takes `SELECT ... FOR UPDATE` on this row, which
     * serialises them. The ledger remains the source of truth for what
     * happened; these columns exist to be locked and to be read cheaply.
     *
     * Their agreement with the ledger is asserted by the reconciliation test.
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // SIGNED, despite balances never being negative in normal operation.
            // A chargeback reversal (Phase 7) claws back money the customer has
            // already spent and can legitimately drive the balance below zero;
            // an unsigned column would need a migration at exactly the moment
            // nobody wants one. The non-negative rule lives in WalletService,
            // which knows about the one case that may break it.
            $table->bigInteger('balance_minor')->default(0);

            // Funds reserved by active holds. Customer side only.
            // available = balance_minor - held_minor
            $table->bigInteger('held_minor')->default(0);

            // Owner earnings awaiting maturity. Not withdrawable.
            $table->bigInteger('pending_minor')->default(0);

            $table->string('status', 20)->default(WalletStatus::Active->value);
            $table->text('frozen_reason')->nullable();
            $table->timestamp('frozen_at')->nullable();

            $table->timestamps();

            $table->index('status');
        });

        // Defence in depth. The real guarantee is WalletService's invariant
        // checks -- these two catch a direct SQL write that bypasses it.
        // Deliberately no constraint on balance_minor: see the chargeback note
        // above. Skipped on SQLite, which ignores CHECK on older versions.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::getConnection()->statement(
                'ALTER TABLE wallets ADD CONSTRAINT chk_wallets_held_non_negative CHECK (held_minor >= 0)'
            );
            Schema::getConnection()->statement(
                'ALTER TABLE wallets ADD CONSTRAINT chk_wallets_pending_non_negative CHECK (pending_minor >= 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
