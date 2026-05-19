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

    // ── C) Ensure 16 roasters exist ──────────────────────────────────────

    public function test_it_creates_all_sixteen_missing_roasters_with_sensible_defaults(): void
    {
        $this->assertSame(0, Roaster::count());

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);

        $this->assertSame(16, Roaster::count(), 'all 16 required roasters created from an empty table');

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
    }

    public function test_create_step_is_idempotent_and_never_duplicates(): void
    {
        // First run creates them.
        $this->artisan('roasters:apply-corrections')->assertExitCode(0);
        $this->assertSame(16, Roaster::count());

        // Second run must be a complete no-op.
        $this->artisan('roasters:apply-corrections')->assertExitCode(0);
        $this->assertSame(16, Roaster::count(), 'second run must not duplicate any roaster');
    }

    public function test_existing_roaster_matched_by_name_is_not_duplicated_or_overwritten(): void
    {
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
        // The other 15 still get created → 16 total.
        $this->assertSame(16, Roaster::count());
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
        $this->assertSame(2, Roaster::count(), 'dry-run must not create the 16 roasters');
    }

    public function test_dry_run_then_real_run_applies_everything(): void
    {
        $hatch = $this->makeRoaster(['name' => 'Hatch Coffee', 'slug' => 'hatch-coffee', 'website' => 'https://stale.example']);

        $this->artisan('roasters:apply-corrections', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame('https://stale.example', $hatch->fresh()->website);
        $this->assertSame(1, Roaster::count());

        $this->artisan('roasters:apply-corrections')->assertExitCode(0);
        $this->assertSame('https://hatchcrafted.com', $hatch->fresh()->website);
        // 1 pre-existing (Hatch) + 16 created.
        $this->assertSame(17, Roaster::count());
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
        // 2 pre-existing + 16 created = 18.
        $this->assertSame(18, Roaster::count());
    }
}
