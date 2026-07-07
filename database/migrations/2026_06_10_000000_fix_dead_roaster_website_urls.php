<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-off data fix: repoint four roasters whose stored website domain is dead
 * (NXDOMAIN) or never hosted the storefront, so the daily import has failed
 * for them since launch — JJ Bean sat on seed data with last_import_status
 * 'error' while its real Shopify catalog lived at jjbeancoffee.com.
 *
 *   jj-bean                    jjbean.ca        → jjbeancoffee.com      (Shopify)
 *   anarchy-coffee-roasters    anarchycoffee.ca → anarchycoffeeroasters.com (Shopify)
 *   foglifter-coffee-roasters  foglifter.ca     → www.fogliftercoffee.com  (Squarespace)
 *   moja-coffee                mojacoffee.com   → shop.mojacoffee.com   (Shopify)
 *
 * The same corrections live in ApplyRoasterCorrections::URL_FIXES (batch A)
 * so fresh environments get them from `roasters:apply-corrections`; this
 * migration lands the fix on environments (prod) where apply-corrections
 * isn't run on the scheduler — same split as the Rogue Wave address fix.
 *
 * Matched by slug + the exact dead URL, so a hand-edited website (e.g. the
 * admin already repointed it) is never clobbered. platform is reset to NULL
 * to force a fresh platform probe on the next import — the cached value was
 * detected against the dead/brochure domain and may not match the new host.
 * Idempotent: after the first run the website guard matches zero rows.
 */
return new class extends Migration
{
    private const FIXES = [
        'jj-bean' => [
            'from' => 'https://jjbean.ca',
            'to' => 'https://jjbeancoffee.com',
        ],
        'anarchy-coffee-roasters' => [
            'from' => 'https://anarchycoffee.ca',
            'to' => 'https://anarchycoffeeroasters.com',
        ],
        'foglifter-coffee-roasters' => [
            'from' => 'https://foglifter.ca',
            'to' => 'https://www.fogliftercoffee.com',
        ],
        'moja-coffee' => [
            'from' => 'https://mojacoffee.com',
            'to' => 'https://shop.mojacoffee.com',
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
        // Irreversible data correction: restoring the dead domains would only
        // re-break the daily import, so down() is intentionally a no-op.
    }
};
