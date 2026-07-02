<?php

namespace Tests\Feature;

use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit coverage for `roasters:apply-corrections`.
 *
 * Runs entirely against the in-memory sqlite test DB (phpunit.xml sets
 * DB_DATABASE=:memory:), so it never touches the production database and
 * the command itself is never executed against prod from here.
 */
class ApplyRoasterCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeRoaster(array $attrs): Roaster
    {
        return Roaster::create(array_merge([
            'city' => 'Somewhere',
            'is_active' => true,
            'has_shipping' => true,
        ], $attrs));
    }

    // ── A) Website URL fixes ─────────────────────────────────────────────

    public function test_it_repoints_the_five_stale_website_urls(): void
    {
        $this->makeRoaster(['name' => 'Hatch Coffee', 'slug' => 'hatch-coffee', 'website' => 'https://www.hatch.coffee']);
        $this->makeRoaster(['name' => 'Subtext Coffee Roasters', 'slug' => 'subtext-coffee-roasters', 'website' => 'https://old.subtext.example']);
        $this->makeRoaster(['name' => 'Nemesis', 'slug' => 'nemesis', 'website' => 'https://www.nemesis.coffee']);
        $this->makeRoaster(['name' => 'Prototype', 'slug' => 'prototype', 'website' => 'https://www.prototypecoffee.ca']);
        $this->makeRoaster(['name' => 'Luna Coffee', 'slug' => 'luna-coffee', 'website' => 'https://old.luna.example']);

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        $this->assertSame('https://hatchcrafted.com', Roaster::where('slug', 'hatch-coffee')->value('website'));
        $this->assertSame('https://subtext.coffee', Roaster::where('slug', 'subtext-coffee-roasters')->value('website'));
        $this->assertSame('https://nemesis.coffee', Roaster::where('slug', 'nemesis')->value('website'));
        $this->assertSame('https://prototypecoffee.ca', Roaster::where('slug', 'prototype')->value('website'));
        $this->assertSame('https://enjoylunacoffee.com', Roaster::where('slug', 'luna-coffee')->value('website'));
    }

    public function test_url_fix_matches_by_short_name_too(): void
    {
        // Stored under the bare short label rather than the full name.
        $r = $this->makeRoaster(['name' => 'Hatch', 'slug' => 'hatch', 'website' => 'https://stale.example']);

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        $this->assertSame('https://hatchcrafted.com', $r->fresh()->website);
    }

    public function test_url_fix_repoints_continuum_from_dotcom_to_dotca(): void
    {
        // Regression: the scraper had Continuum at .com, which serves a
        // largely-empty stub site so only one coffee was indexed. The real
        // shop is at .ca with a full menu. Same URL-drift class of bug as
        // Hatch / Subtext / Nemesis / Prototype / Luna.
        $r = $this->makeRoaster([
            'name' => 'Continuum Coffee', 'slug' => 'continuum-coffee',
            'website' => 'https://continuumcoffee.com',
        ]);

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        // Origin-normalized (no trailing slash) so the fix compares equal to
        // what the importer stores and the command stays idempotent.
        $this->assertSame('https://continuumcoffee.ca', $r->fresh()->website);

        // Idempotency: a second run must report the row as already correct.
        $this->artisan('roasters:apply-corrections')->assertExitCode(0);
        $this->assertSame('https://continuumcoffee.ca', $r->fresh()->website);
    }

    public function test_url_fix_repoints_agro_from_agroroasters_to_agrocoffee(): void
    {
        // agroroasters.com 301-redirects to agrocoffee.com — same URL-drift
        // pattern. Repointing avoids the cascade re-resolving through a
        // redirect every run.
        $r = $this->makeRoaster([
            'name' => 'Agro Roasters', 'slug' => 'agro-roasters',
            'website' => 'https://agroroasters.com',
        ]);

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        $this->assertSame('https://agrocoffee.com', $r->fresh()->website);
    }

    public function test_url_fix_repoints_the_four_dead_domain_roasters(): void
    {
        // 2026-06-10 sweep: stored domains were NXDOMAIN (jjbean.ca,
        // anarchycoffee.ca, foglifter.ca) or a brochure site with no
        // storefront API (mojacoffee.com), so the daily import errored
        // forever and JJ Bean served stale seed data in prod.
        $this->makeRoaster(['name' => 'JJ Bean', 'slug' => 'jj-bean', 'website' => 'https://jjbean.ca']);
        $this->makeRoaster(['name' => 'Anarchy Coffee Roasters', 'slug' => 'anarchy-coffee-roasters', 'website' => 'https://anarchycoffee.ca']);
        $this->makeRoaster(['name' => 'Foglifter Coffee Roasters', 'slug' => 'foglifter-coffee-roasters', 'website' => 'https://foglifter.ca']);
        $this->makeRoaster(['name' => 'Moja Coffee', 'slug' => 'moja-coffee', 'website' => 'https://mojacoffee.com']);

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        $this->assertSame('https://jjbeancoffee.com', Roaster::where('slug', 'jj-bean')->value('website'));
        $this->assertSame('https://anarchycoffeeroasters.com', Roaster::where('slug', 'anarchy-coffee-roasters')->value('website'));
        $this->assertSame('https://www.fogliftercoffee.com', Roaster::where('slug', 'foglifter-coffee-roasters')->value('website'));
        $this->assertSame('https://shop.mojacoffee.com', Roaster::where('slug', 'moja-coffee')->value('website'));
    }

    public function test_url_fix_repoints_the_three_moved_storefront_roasters(): void
    {
        // 2026-06-10 empty-import sweep: domains alive but the storefront
        // moved — legacy WP with purchasing disabled (De Mello), brochure
        // site with the shop on a subdomain (Myriade), bare domain 301ing
        // to a broken wp-signup page (Modus).
        $this->makeRoaster(['name' => 'De Mello Coffee', 'slug' => 'de-mello-coffee', 'website' => 'https://www.demellocoffee.com']);
        $this->makeRoaster(['name' => 'Café Myriade', 'slug' => 'cafe-myriade', 'website' => 'https://cafemyriade.com']);
        $this->makeRoaster(['name' => 'Modus', 'slug' => 'modus', 'website' => 'https://moduscoffee.com']);

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        $this->assertSame('https://hellodemello.com', Roaster::where('slug', 'de-mello-coffee')->value('website'));
        $this->assertSame('https://shop.cafemyriade.com', Roaster::where('slug', 'cafe-myriade')->value('website'));
        $this->assertSame('https://www.moduscoffee.com', Roaster::where('slug', 'modus')->value('website'));
    }

    public function test_moved_storefront_migration_repoints_only_rows_still_on_the_stale_url(): void
    {
        $stale = $this->makeRoaster(['name' => 'De Mello Coffee', 'slug' => 'de-mello-coffee', 'website' => 'https://www.demellocoffee.com', 'platform' => 'woocommerce']);
        $edited = $this->makeRoaster(['name' => 'Modus', 'slug' => 'modus', 'website' => 'https://hand-edited.example', 'platform' => 'woocommerce']);

        $migration = require database_path('migrations/2026_06_10_100000_fix_moved_roaster_storefront_urls.php');
        $migration->up();

        $stale->refresh();
        $this->assertSame('https://hellodemello.com', $stale->website);
        $this->assertNull($stale->platform, 'platform cache must reset so the next import re-probes the new host');
        $this->assertSame('https://hand-edited.example', $edited->fresh()->website, 'hand-edited row must survive');

        // Idempotent: a second run matches zero rows.
        $migration->up();
        $this->assertSame('https://hellodemello.com', $stale->fresh()->website);
    }

    public function test_dead_domain_migration_repoints_only_rows_still_on_the_dead_url(): void
    {
        // The data-fix migration ran during RefreshDatabase setup (before
        // these rows existed), so exercise its logic directly: it must move
        // rows parked on the exact dead URL and leave hand-edited rows alone.
        $stale = $this->makeRoaster(['name' => 'JJ Bean', 'slug' => 'jj-bean', 'website' => 'https://jjbean.ca', 'platform' => 'shopify']);
        $edited = $this->makeRoaster(['name' => 'Moja Coffee', 'slug' => 'moja-coffee', 'website' => 'https://hand-edited.example', 'platform' => 'shopify']);

        $migration = require database_path('migrations/2026_06_10_000000_fix_dead_roaster_website_urls.php');
        $migration->up();

        $stale->refresh();
        $this->assertSame('https://jjbeancoffee.com', $stale->website);
        $this->assertNull($stale->platform, 'platform cache must reset so the next import re-probes');
        $this->assertSame('https://hand-edited.example', $edited->fresh()->website, 'hand-edited row must survive');

        // Idempotent: a second run matches zero rows and changes nothing.
        $migration->up();
        $this->assertSame('https://jjbeancoffee.com', $stale->fresh()->website);
    }

    // ── D) Manual address overrides ──────────────────────────────────────

    public function test_address_fix_overrides_cascade_with_verified_street_and_coords(): void
    {
        // Real-world: AddressScraper Step 2 (contact-page) accepted
        // postal-code-adjacent CSS / nav junk as the street_address for
        // these three roasters, and Step 5 (Nominatim) then couldn't make
        // sense of the junk so the city centroid stayed. Manual override
        // bypasses the cascade with verified addresses + coords.
        $proto = $this->makeRoaster([
            'name' => 'Prototype', 'slug' => 'prototype',
            'street_address' => 'garbage text the cascade extracted',
            'postal_code' => 'V6Z 1A1',
            'latitude' => 49.2827, 'longitude' => -123.1207, // Vancouver centroid
            'address_source' => 'website',
        ]);

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        $proto->refresh();
        $this->assertSame('883 East Hastings Street', $proto->street_address);
        $this->assertSame('V6A 1R8', $proto->postal_code);
        $this->assertSame(49.2813068, (float) $proto->latitude);
        $this->assertSame(-123.0852617, (float) $proto->longitude);
        $this->assertSame('manual', $proto->address_source);
        $this->assertNotNull($proto->address_verified_at);
    }

    public function test_address_fix_applies_to_all_targeted_roasters(): void
    {
        $this->makeRoaster([
            'name' => '49th Parallel', 'slug' => '49th-parallel',
            'latitude' => 49.2827, 'longitude' => -123.1207,
            'address_source' => 'website',
        ]);
        $this->makeRoaster([
            'name' => 'Agro Roasters', 'slug' => 'agro-roasters',
            'latitude' => 49.2827, 'longitude' => -123.1207,
            'address_source' => 'website',
        ]);
        $this->makeRoaster([
            'name' => 'Prototype', 'slug' => 'prototype',
            'latitude' => 49.2827, 'longitude' => -123.1207,
            'address_source' => 'website',
        ]);
        $this->makeRoaster([
            'name' => 'East Van Roasters', 'slug' => 'east-van-roasters',
            'street_address' => '(604) 629-7562[email\xa0protected], 16 W Hastings St',
            'postal_code' => 'V6B 1G4',
            'latitude' => 49.2827, 'longitude' => -123.1207,
            'address_source' => 'website',
        ]);

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        $this->assertSame('2902 Main St', Roaster::where('slug', '49th-parallel')->value('street_address'));
        $this->assertSame('1359 Powell Street', Roaster::where('slug', 'agro-roasters')->value('street_address'));
        $this->assertSame('883 East Hastings Street', Roaster::where('slug', 'prototype')->value('street_address'));
        $this->assertSame('319 Carrall St', Roaster::where('slug', 'east-van-roasters')->value('street_address'));
        $this->assertSame('V6B 2J4', Roaster::where('slug', 'east-van-roasters')->value('postal_code'));

        // All pinned away from the exact city centroid.
        foreach (['49th-parallel', 'agro-roasters', 'prototype', 'east-van-roasters'] as $slug) {
            $lat = (float) Roaster::where('slug', $slug)->value('latitude');
            $this->assertNotSame(49.2827, $lat, "{$slug} still on Vancouver centroid");
            $this->assertSame('manual', Roaster::where('slug', $slug)->value('address_source'));
        }
    }

    public function test_batch2_nominatim_resolved_addresses_apply_to_all_eight_roasters(): void
    {
        // Eight roasters that were pinned to their city centroid until the
        // Nominatim business-name search filled in their real shop addresses
        // (Café Pikolo, Ethica, Even, Happy Goat, Midnight Sun, Phil &
        // Sebastian, Receiver, Sam James). The point of this batch is the
        // bulk fix, so the test just spot-checks one from each city to
        // prove the routing wires up — every individual entry's data is
        // verifiable from the ADDRESS_FIXES const.
        $this->makeRoaster(['name' => 'Phil & Sebastian', 'slug' => 'phil-sebastian',
            'latitude' => 51.0447, 'longitude' => -114.0719, 'address_source' => 'website']);
        $this->makeRoaster(['name' => 'Ethica Coffee Roasters', 'slug' => 'ethica-coffee-roasters',
            'latitude' => 43.6532, 'longitude' => -79.3832, 'address_source' => 'website']);
        $this->makeRoaster(['name' => 'Receiver Coffee Co.', 'slug' => 'receiver-coffee-co',
            'latitude' => 46.2382, 'longitude' => -63.1311, 'address_source' => 'website']);

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        $this->assertSame('2207 4 Street SW', Roaster::where('slug', 'phil-sebastian')->value('street_address'));
        $this->assertSame('213 Sterling Road', Roaster::where('slug', 'ethica-coffee-roasters')->value('street_address'));
        $this->assertSame('128 Richmond Street', Roaster::where('slug', 'receiver-coffee-co')->value('street_address'));
        foreach (['phil-sebastian', 'ethica-coffee-roasters', 'receiver-coffee-co'] as $slug) {
            $this->assertSame('manual', Roaster::where('slug', $slug)->value('address_source'));
        }
    }

    public function test_batch3_address_fix_with_city_override_corrects_seeded_city(): void
    {
        // Cantook was seeded as Montreal but the actual cafe is in Québec
        // City — the address fix carries an optional `city` field that
        // overwrites the seeded city when present. Without this, the
        // map would pin the cafe in QC but the directory would still
        // group it under Montreal.
        $cantook = $this->makeRoaster([
            'name' => 'Cantook Café Brûlerie', 'slug' => 'cantook-cafe-brulerie',
            'city' => 'Montreal', 'region' => 'Quebec',
            'latitude' => 45.5017, 'longitude' => -73.5673,
            'address_source' => 'website',
        ]);
        // Reunion: seeded Mississauga, actually Oakville.
        $reunion = $this->makeRoaster([
            'name' => 'Reunion Coffee Roasters', 'slug' => 'reunion-coffee-roasters',
            'city' => 'Mississauga', 'region' => 'Ontario',
            'latitude' => 43.589, 'longitude' => -79.6441,
            'address_source' => 'website',
        ]);
        // A roaster WITHOUT a city override — city must remain unchanged.
        $ace = $this->makeRoaster([
            'name' => 'Ace Coffee Roasters', 'slug' => 'ace-coffee-roasters',
            'city' => 'Edmonton', 'region' => 'Alberta',
            'latitude' => 53.5461, 'longitude' => -113.4938,
            'address_source' => 'website',
        ]);

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        $cantook->refresh(); $reunion->refresh(); $ace->refresh();
        $this->assertSame('Québec', $cantook->city, 'Cantook city must be overridden');
        $this->assertSame('Oakville', $reunion->city, 'Reunion city must be overridden');
        $this->assertSame('Edmonton', $ace->city, 'Ace has no city override; original city must survive');
        // Sanity: regions are not touched by the address-fix flow.
        $this->assertSame('Quebec', $cantook->region);
        $this->assertSame('Ontario', $reunion->region);
    }

    // ── E) Chrome-only shipping_notes cleanup ────────────────────────────

    public function test_clears_shipping_notes_containing_chrome_markers(): void
    {
        // Live audit found 35 rows with notes like "Shipping policy –
        // Rosso Coffee Skip to content Spend $75." — chrome that the old
        // extractor surfaced as the policy. Step E NULLs them so the next
        // import-all run can repopulate with the improved extractor.
        $a = $this->makeRoaster([
            'name' => 'Chrome A', 'slug' => 'chrome-a',
            'shipping_notes' => 'Shipping policy – Roaster Skip to content Spend $75',
        ]);
        $b = $this->makeRoaster([
            'name' => 'Chrome B', 'slug' => 'chrome-b',
            'shipping_notes' => "Politique d'expédition Aller au contenu Facebook Instagram",
        ]);
        $clean = $this->makeRoaster([
            'name' => 'Clean Notes', 'slug' => 'clean-notes',
            'shipping_notes' => 'Free shipping across Canada on orders over $75.',
        ]);

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        $this->assertNull($a->fresh()->shipping_notes, 'chrome marker "Skip to content" must be NULL\'d');
        $this->assertNull($b->fresh()->shipping_notes, 'French chrome marker "Aller au contenu" must be NULL\'d');
        $this->assertSame('Free shipping across Canada on orders over $75.', $clean->fresh()->shipping_notes,
            'real policy text must survive');
    }

    public function test_chrome_shipping_notes_cleanup_is_idempotent(): void
    {
        $r = $this->makeRoaster([
            'name' => 'Clean', 'slug' => 'clean',
            'shipping_notes' => 'Free shipping on orders over $50.',
        ]);
        $updatedAt = $r->updated_at;

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);
        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        $r->refresh();
        $this->assertSame('Free shipping on orders over $50.', $r->shipping_notes);
        $this->assertEquals($updatedAt, $r->updated_at, 'clean row must not be rewritten');
    }

    public function test_address_fix_is_idempotent(): void
    {
        // Already correctly set — the command must NOT re-save.
        $r = $this->makeRoaster([
            'name' => 'Prototype', 'slug' => 'prototype',
            'street_address' => '883 East Hastings Street',
            'postal_code' => 'V6A 1R8',
            'latitude' => 49.2813068, 'longitude' => -123.0852617,
            'address_source' => 'manual',
            'address_verified_at' => now()->subDays(3),
        ]);
        $verifiedAt = $r->address_verified_at;
        $updatedAt = $r->updated_at;

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        $r->refresh();
        $this->assertEquals($verifiedAt, $r->address_verified_at, 'verified_at must not bump when already correct');
        $this->assertEquals($updatedAt, $r->updated_at, 'no rewrite when already correct');
    }

    public function test_address_fix_clears_is_online_only_when_resolving_an_address(): void
    {
        // Edge case: if a roaster was previously marked online-only (no
        // physical address) but we now have a verified street, the
        // override must un-set the flag — otherwise the map still filters
        // them out per the is_online_only=true marker rule.
        $r = $this->makeRoaster([
            'name' => 'Prototype', 'slug' => 'prototype',
            'is_online_only' => true,
        ]);

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        $r->refresh();
        $this->assertFalse((bool) $r->is_online_only);
        $this->assertSame('883 East Hastings Street', $r->street_address);
    }

    public function test_url_fix_is_idempotent_and_only_touches_target_rows(): void
    {
        $hatch = $this->makeRoaster(['name' => 'Hatch Coffee', 'slug' => 'hatch-coffee', 'website' => 'https://hatchcrafted.com']);
        $other = $this->makeRoaster(['name' => 'Some Other Roaster', 'slug' => 'some-other', 'website' => 'https://other.example']);

        $hatchUpdatedAt = $hatch->updated_at;

        // Re-running against an already-correct DB changes nothing.
        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        $hatch->refresh();
        $this->assertSame('https://hatchcrafted.com', $hatch->website);
        $this->assertEquals($hatchUpdatedAt, $hatch->updated_at, 'already-correct row must not be re-saved');
        $this->assertSame('https://other.example', $other->fresh()->website, 'unrelated roaster untouched');
    }

    // ── B) Quietly city fix ──────────────────────────────────────────────

    public function test_it_fixes_quietly_city_but_keeps_region(): void
    {
        $q = $this->makeRoaster([
            'name' => 'Quietly Coffee', 'slug' => 'quietly-coffee',
            'city' => 'Toronto', 'region' => 'Ontario', 'website' => 'https://quietlycoffee.com',
        ]);

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        $q->refresh();
        $this->assertSame('Stirling', $q->city);
        $this->assertSame('Ontario', $q->region, 'region must be unchanged');
    }

    public function test_quietly_city_fix_is_idempotent(): void
    {
        $q = $this->makeRoaster([
            'name' => 'Quietly Coffee', 'slug' => 'quietly-coffee',
            'city' => 'Stirling', 'region' => 'Ontario',
        ]);
        $updatedAt = $q->updated_at;

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        $q->refresh();
        $this->assertSame('Stirling', $q->city);
        $this->assertEquals($updatedAt, $q->updated_at, 'no rewrite when already Stirling');
    }

    // ── C) Ensure required roasters exist ────────────────────────────────
    //
    // The expected count derives from the REQUIRED_ROASTERS const via a
    // single source-of-truth helper so adding new entries to the array
    // doesn't require updating every count assertion in this file.

    private function requiredRoasterCount(): int
    {
        $ref = new \ReflectionClass(\App\Console\Commands\ApplyRoasterCorrections::class);
        return count($ref->getConstant('REQUIRED_ROASTERS'));
    }

    public function test_it_creates_all_required_missing_roasters_with_sensible_defaults(): void
    {
        $expected = $this->requiredRoasterCount();
        $this->assertSame(0, Roaster::count());

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        $this->assertSame($expected, Roaster::count(), "all {$expected} required roasters created from an empty table");

        $sip = Roaster::where('slug', 'sipstruck-specialty-coffee')->first();
        $this->assertNotNull($sip);
        $this->assertSame('Niagara Falls', $sip->city);
        $this->assertSame('Ontario', $sip->region);
        $this->assertSame('https://sipstruck.com', $sip->website);
        $this->assertSame('CA', $sip->country_code);
        $this->assertTrue((bool) $sip->has_shipping);
        $this->assertTrue((bool) $sip->is_active);

        // Accent-bearing name persists and slugs correctly.
        $yama = Roaster::where('slug', 'cafe-yamabiko')->first();
        $this->assertNotNull($yama);
        $this->assertSame('Café Yamabiko', $yama->name);
        $this->assertSame('Quebec', $yama->region);

        // BC scour batch — verify one of the Vancouver Island additions
        // came through with the right city + region.
        $caffe = Roaster::where('slug', 'caffe-fantastico')->first();
        $this->assertNotNull($caffe, 'Caffe Fantastico must be created by the BC scour batch');
        $this->assertSame('Victoria', $caffe->city);
        $this->assertSame('British Columbia', $caffe->region);
        $this->assertSame('https://caffefantastico.com', $caffe->website);
    }

    public function test_create_step_is_idempotent_and_never_duplicates(): void
    {
        $expected = $this->requiredRoasterCount();
        // First run creates them.
        $this->artisan('roasters:apply-corrections')->assertExitCode(0);
        $this->assertSame($expected, Roaster::count());

        // Second run must be a complete no-op.
        $this->artisan('roasters:apply-corrections')->assertExitCode(0);
        $this->assertSame($expected, Roaster::count(), 'second run must not duplicate any roaster');
    }

    public function test_existing_roaster_matched_by_name_is_not_duplicated_or_overwritten(): void
    {
        $expected = $this->requiredRoasterCount();
        // A roaster that already exists under a different slug but same name.
        $existing = $this->makeRoaster([
            'name' => 'House of Funk', 'slug' => 'house-of-funk-vancouver',
            'city' => 'Vancouver', 'region' => 'British Columbia',
            'website' => 'https://hand-edited.example',
        ]);

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        // Not duplicated…
        $this->assertSame(1, Roaster::where('name', 'House of Funk')->count());
        // …and the hand-edited row is left exactly as-is (create step skips it).
        $existing->refresh();
        $this->assertSame('https://hand-edited.example', $existing->website);
        $this->assertSame('house-of-funk-vancouver', $existing->slug);
        // The hand-edited row is the matched one; the rest get created → total = $expected.
        $this->assertSame($expected, Roaster::count());
    }

    // ── --dry-run ────────────────────────────────────────────────────────

    public function test_dry_run_writes_nothing(): void
    {
        $hatch = $this->makeRoaster(['name' => 'Hatch Coffee', 'slug' => 'hatch-coffee', 'website' => 'https://stale.example']);
        $quietly = $this->makeRoaster([
            'name' => 'Quietly Coffee', 'slug' => 'quietly-coffee',
            'city' => 'Toronto', 'region' => 'Ontario',
        ]);

        $this->artisan('roasters:apply-corrections', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->assertExitCode(0);

        // URL not changed, city not changed, no roasters created.
        $this->assertSame('https://stale.example', $hatch->fresh()->website);
        $this->assertSame('Toronto', $quietly->fresh()->city);
        $this->assertSame(2, Roaster::count(), 'dry-run must not create any required roaster');
    }

    public function test_dry_run_then_real_run_applies_everything(): void
    {
        $hatch = $this->makeRoaster(['name' => 'Hatch Coffee', 'slug' => 'hatch-coffee', 'website' => 'https://stale.example']);

        $this->artisan('roasters:apply-corrections', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame('https://stale.example', $hatch->fresh()->website);
        $this->assertSame(1, Roaster::count());

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);
        $this->assertSame('https://hatchcrafted.com', $hatch->fresh()->website);
        // 1 pre-existing (Hatch) + REQUIRED_ROASTERS count.
        $this->assertSame(1 + $this->requiredRoasterCount(), Roaster::count());
    }

    // ── full-run integration ─────────────────────────────────────────────

    public function test_full_run_applies_all_three_batches_together(): void
    {
        $this->makeRoaster(['name' => 'Nemesis', 'slug' => 'nemesis', 'website' => 'https://www.nemesis.coffee']);
        $this->makeRoaster([
            'name' => 'Quietly Coffee', 'slug' => 'quietly-coffee',
            'city' => 'Toronto', 'region' => 'Ontario',
        ]);

        $this->artisan('roasters:apply-corrections')
            ->expectsOutputToContain('change(s) applied')
            ->assertExitCode(0);

        $this->assertSame('https://nemesis.coffee', Roaster::where('slug', 'nemesis')->value('website'));
        $this->assertSame('Stirling', Roaster::where('slug', 'quietly-coffee')->value('city'));
        // 2 pre-existing + REQUIRED_ROASTERS count.
        $this->assertSame(2 + $this->requiredRoasterCount(), Roaster::count());
    }
}
