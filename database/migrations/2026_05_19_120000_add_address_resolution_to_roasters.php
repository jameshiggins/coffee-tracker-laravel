<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Q-AR: address-resolution cascade telemetry on the roasters row.
 *
 * After scraping each roaster's site for a precise street address, we stamp
 * which step in the cascade actually produced the hit (`address_source`),
 * when it was resolved (`address_verified_at`), and—if every step came up
 * empty—`is_online_only=true` so the React map suppresses a city-centroid
 * marker for an online-only operation. `google_place_id` is cached for a
 * future Places-API enrichment pass (hours, photos, phone).
 *
 * Idempotent on re-run: each column is added only when missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roasters', function (Blueprint $table) {
            if (!Schema::hasColumn('roasters', 'address_source')) {
                $table->string('address_source', 16)->nullable()->after('longitude');
            }
            if (!Schema::hasColumn('roasters', 'address_verified_at')) {
                $table->timestamp('address_verified_at')->nullable()->after('address_source');
            }
            if (!Schema::hasColumn('roasters', 'is_online_only')) {
                $table->boolean('is_online_only')->default(false)->after('address_verified_at');
            }
            if (!Schema::hasColumn('roasters', 'google_place_id')) {
                $table->string('google_place_id')->nullable()->after('is_online_only');
            }
        });
    }

    public function down(): void
    {
        Schema::table('roasters', function (Blueprint $table) {
            foreach (['google_place_id', 'is_online_only', 'address_verified_at', 'address_source'] as $col) {
                if (Schema::hasColumn('roasters', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
