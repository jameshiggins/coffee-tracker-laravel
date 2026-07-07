<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-off data fix, round two of the 2026-06-10 import-health sweep: three
 * roasters whose stored domain is alive but no longer hosts the storefront,
 * so every daily import recorded 'empty' (the fetch succeeded and found no
 * purchasable products):
 *
 *   de-mello-coffee  www.demellocoffee.com → hellodemello.com      (Shopify;
 *       the old WordPress site has purchasing disabled — every product
 *       reports price=0 / "Read more")
 *   cafe-myriade     cafemyriade.com       → shop.cafemyriade.com  (Shopify;
 *       bare domain is the cafe brochure site)
 *   modus            moduscoffee.com       → www.moduscoffee.com   (WooCommerce;
 *       the bare domain 301s to a broken wp-signup.php multisite page)
 *
 * Same split as 2026_06_10_000000_fix_dead_roaster_website_urls: the
 * corrections also live in ApplyRoasterCorrections::URL_FIXES for fresh
 * environments; this migration lands them on prod, where apply-corrections
 * isn't on the scheduler. Matched by slug + the exact stale URL so a
 * hand-edited row is never clobbered; platform resets to NULL to force a
 * fresh probe against the new host. Idempotent — after the first run the
 * website guard matches zero rows.
 */
return new class extends Migration
{
    private const FIXES = [
        'de-mello-coffee' => [
            'from' => 'https://www.demellocoffee.com',
            'to' => 'https://hellodemello.com',
        ],
        'cafe-myriade' => [
            'from' => 'https://cafemyriade.com',
            'to' => 'https://shop.cafemyriade.com',
        ],
        'modus' => [
            'from' => 'https://moduscoffee.com',
            'to' => 'https://www.moduscoffee.com',
        ],
    ];

    public function up(): void
    {
        foreach (self::FIXES as $slug => $fix) {
            DB::table('roasters')
                ->where('slug', $slug)
                ->where('website', $fix['from'])
                ->update([
                    'website' => $fix['to'],
                    'platform' => null,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Irreversible data correction: restoring the brochure/broken URLs
        // would only re-empty the daily import, so down() is a no-op.
    }
};
