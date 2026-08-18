<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Both galleries now hold video alongside photos. A `media_type` column
     * rather than a second pair of tables: the two kinds share every other
     * column (path, caption, sort_order) and every relation, cap and delete
     * rule they already have -- only how the browser renders them differs.
     */
    public function up(): void
    {
        Schema::table('business_images', function (Blueprint $table) {
            $table->string('media_type', 10)->default('image')->after('path');
        });

        Schema::table('spot_images', function (Blueprint $table) {
            $table->string('media_type', 10)->default('image')->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('business_images', function (Blueprint $table) {
            $table->dropColumn('media_type');
        });

        Schema::table('spot_images', function (Blueprint $table) {
            $table->dropColumn('media_type');
        });
    }
};
