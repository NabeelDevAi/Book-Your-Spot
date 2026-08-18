<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Platform-wide holiday calendar, Admin-managed (see the pricing decision
     * agreed with the product owner: one global list rather than per-venue).
     *
     * A Spot bills its weekend rate on Saturdays, Sundays, a listed holiday,
     * AND the day immediately before a listed holiday -- so a single date here
     * affects two calendar days of pricing, not one.
     */
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
