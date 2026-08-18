<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two pricing decisions land together because they touch the same rows:
     *
     *  1. `price_amount` becomes the WEEKDAY rate. `weekend_price_amount` is
     *     the Sat/Sun/holiday rate. NULL means "same as the weekday rate" --
     *     the same "null means inherit" convention `operating_hours_override`
     *     already uses -- so a Spot that has never touched this is priced
     *     exactly as it always was.
     *
     *     Existing rows are backfilled to an EXPLICIT copy of price_amount
     *     rather than left NULL, per the agreed migration behaviour: nobody's
     *     price silently changes today, and an owner can see their current
     *     rate sitting in the weekend field rather than a blank they might
     *     mistake for unset.
     *
     *  2. `max_duration_minutes` is dropped. There is no owner-set ceiling any
     *     more -- the real limit is simply how much of that day's operating
     *     hours a booking can fit into (BookingValidator + AvailabilityService
     *     already enforce that independently). Only a minimum survives.
     */
    public function up(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->decimal('weekend_price_amount', 10, 2)->nullable()->after('price_amount');
        });

        DB::table('spots')->update(['weekend_price_amount' => DB::raw('price_amount')]);

        Schema::table('spots', function (Blueprint $table) {
            $table->dropColumn('max_duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('spots', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_duration_minutes')->default(720)->after('min_duration_minutes');
        });

        DB::table('spots')->update(['max_duration_minutes' => 720]);

        Schema::table('spots', function (Blueprint $table) {
            $table->dropColumn('weekend_price_amount');
        });
    }
};
