<?php

use App\Enums\GameStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin-managed master category list (SRS FR-2.3, FR-3.3).
     *
     * Owners pick from this list and cannot invent categories, which is what
     * stops the taxonomy fragmenting into "PS5" / "Playstation 5" / "PS 5".
     */
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('icon', 40)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default(GameStatus::Active->value);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
