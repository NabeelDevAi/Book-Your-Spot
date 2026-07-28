<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Business's offering of one master Game category.
     *
     * This is a first-class table rather than a plain pivot because Spots hang
     * off it (SRS 1.4): a Spot belongs to "this venue's snooker offering", not
     * to the venue and the category independently.
     */
    public function up(): void
    {
        Schema::create('business_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['business_id', 'game_id']);
            $table->index('game_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_games');
    }
};
