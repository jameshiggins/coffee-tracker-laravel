<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elevation in metres above sea level. Most specialty coffee is grown
 * 1200-2200m. Stored as a single int (the midpoint when the source
 * gives a range like "1800-2100m") so it sorts and filters cleanly.
 *
 * Extracted heuristically from product descriptions by
 * App\Services\CoffeeFieldExtractor — many roasters bury this in prose
 * rather than expose it as a structured field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coffees', function (Blueprint $table) {
            $table->unsignedSmallInteger('elevation_meters')->nullable()->after('varietal');
        });
    }

    public function down(): void
    {
        Schema::table('coffees', function (Blueprint $table) {
            $table->dropColumn('elevation_meters');
        });
    }
};
