<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money fields on the reservation.
     *
     * `total_price` (decimal) stays as it is for display and reporting
     * continuity. `amount_paid_minor` is the authoritative figure for anything
     * financial -- it is what was held, captured and refunded, in the integer
     * paisa the ledger sums exactly.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // What the customer's wallet was actually charged. Nullable for the
            // rows that predate wallets.
            $table->unsignedBigInteger('amount_paid_minor')->nullable()->after('total_price');

            // The refund tiers in force when the customer booked.
            //
            // Snapshotted for exactly the reason the price is (SRS 9.10): a
            // dispute is argued against the policy the customer agreed to, not
            // whatever the config says months later. Without this, editing
            // config/wallet.php silently rewrites the terms of every live
            // booking on the platform.
            $table->json('refund_policy_snapshot')->nullable()->after('amount_paid_minor');

            // How the money was split when the booking ended. Both null until
            // a cancellation or no-show resolves it; together they always sum
            // to amount_paid_minor.
            $table->unsignedBigInteger('refund_amount_minor')->nullable()->after('refund_policy_snapshot');
            $table->unsignedBigInteger('owner_settlement_minor')->nullable()->after('refund_amount_minor');

            // When the Owner's earnings moved from pending to withdrawable.
            // Null means "not yet"; the maturity sweep uses it as its cursor.
            $table->timestamp('earnings_matured_at')->nullable()->after('owner_settlement_minor');

            // Drives the maturity sweep: completed bookings whose earnings have
            // not been released yet.
            $table->index(['earnings_matured_at', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex(['earnings_matured_at', 'status']);
            $table->dropColumn([
                'amount_paid_minor',
                'refund_policy_snapshot',
                'refund_amount_minor',
                'owner_settlement_minor',
                'earnings_matured_at',
            ]);
        });
    }
};
