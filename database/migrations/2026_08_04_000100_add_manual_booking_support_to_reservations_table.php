<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            // A walk-in or phone booking has no requesting User -- the Owner
            // recorded it on the customer's behalf. Platform bookings keep a
            // required user_id; this only relaxes the column itself.
            $table->foreignId('user_id')->nullable()->change();

            // Manual bookings are created already confirmed, so there is
            // nothing to auto-expire and no deadline to compute.
            $table->dateTime('response_deadline')->nullable()->change();

            // Only populated for a manual booking with no linked User account
            // -- see Reservation::customerDisplayName()/customerDisplayPhone().
            $table->string('customer_name', 150)->nullable()->after('user_id');
            $table->string('customer_phone', 30)->nullable()->after('customer_name');

            // How the booking reached the system: online (the default, every
            // existing row), or entered by staff for a walk-in / phone call.
            $table->string('channel', 20)->default('online')->after('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['customer_name', 'customer_phone', 'channel']);
            $table->dateTime('response_deadline')->nullable(false)->change();
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
