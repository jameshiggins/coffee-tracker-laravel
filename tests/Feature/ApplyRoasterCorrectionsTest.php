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

        $this->assertSame('https://continuumcoffee.ca/', $r->fresh()->website);
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
