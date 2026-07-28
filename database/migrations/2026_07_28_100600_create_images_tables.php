<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two narrow tables rather than one polymorphic `images` table: there are
     * exactly two owners of images in V1, both need a real foreign key with
     * cascade behaviour, and a polymorphic morph column would buy nothing but
     * lost referential integrity.
     */
    public function up(): void
    {
        Schema::create('business_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('caption')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['business_id', 'sort_order']);
        });

        Schema::create('spot_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('spot_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('caption')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['spot_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spot_images');
        Schema::dropIfExists('business_images');
    }
};
