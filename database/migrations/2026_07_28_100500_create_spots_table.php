<?php

use App\Enums\SpotStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The physical bookable unit: "Snooker Table 1", "PS5 Room A", "Court 2".
     * This is the thing reservations actually attach to.
     */
    public function up(): void
    {
        Schema::create('spots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_game_id')->constrained()->cascadeOnDelete();

            // Denormalised so availability and admin queries can filter by
            // venue without joining through business_games every time.
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            // Pricing: rate per billing unit, e.g. Rs 100 per 10 minutes,
            // Rs 2,500 per 60 minutes.
            $table->decimal('price_amount', 10, 2);
            $table->unsignedSmallInteger('price_unit_minutes');

            // A booking must be within these bounds AND an exact multiple of
            // price_unit_minutes (SRS 9.7).
            $table->unsignedSmallInteger('min_duration_minutes');
            $table->unsignedSmallInteger('max_duration_minutes');

            $table->string('status', 20)->default(SpotStatus::Active->value);

            // NULL means "inherit the Business hours" -- deliberately distinct
            // from an all-days-closed schedule.
            $table->json('operating_hours_override')->nullable();

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();
            // SRS 9.9: soft delete only; a hard delete is permitted solely when
            // the Spot has never held a reservation.
            $table->softDeletes();

            $table->index(['business_id', 'status']);
            $table->index(['business_game_id', 'status', 'sort_order']);
            $table->index(['status', 'price_amount']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spots');
    }
};
