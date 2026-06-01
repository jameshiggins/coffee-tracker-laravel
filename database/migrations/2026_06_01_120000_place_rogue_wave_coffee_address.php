<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-off data fix: place Rogue Wave Coffee on the map.
 *
 * The address-scrape cascade can't reach roguewavecoffee.ca (the site
 * bot-blocks the scraper), so every step came up empty and the roaster was
 * flagged is_online_only=true with null coordinates. Rogue Wave does have a
 * public tasting bar at 11322 119 Street NW, Edmonton (T5G 3C2) — confirmed
 * via OSM, which resolves the "ROGUE WAVE" shop node directly for exact
 * coordinates + postal code. The same correction also lives in
 * app/Console/Commands/ApplyRoasterCorrections.php (batch 4) so future
 * cascade runs leave it alone; this migration lands the fix on environments
 * (prod) where apply-corrections isn't run on the scheduler.
 *
 * Matched by slug because row ids differ across environments. Stamped
 * source='manual' so the monthly scrape-addresses cascade never clobbers it.
 * Idempotent: re-running writes the same values; on databases without the
 * roaster (e.g. a fresh test DB) it matches zero rows and is a harmless no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roasters')
            ->where('slug', 'rogue-wave-coffee')
            ->update([
                'street_address' => '11322 119 Street NW',
                'postal_code' => 'T5G 3C2',
                'latitude' => 53.5630794,
                'longitude' => -113.5275158,
                'address_source' => 'manual',
                'address_verified_at' => now(),
                'is_online_only' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Irreversible data correction: restoring the previous (incorrect)
        // online-only flag and null coordinates would only re-introduce the
        // bug, so down() is intentionally a no-op.
    }
};
