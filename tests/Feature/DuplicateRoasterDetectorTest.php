<?php

namespace Tests\Feature;

use App\Models\Roaster;
use App\Services\DuplicateRoasterDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trust#7: duplicate-roaster detection (shared host, identical canonical name,
 * fuzzy name match) plus the read-only `roasters:find-duplicates` command.
 */
class DuplicateRoasterDetectorTest extends TestCase
{
    use RefreshDatabase;

    private function roaster(string $name, string $slug, string $website, bool $active = true): Roaster
    {
        return Roaster::create([
            'name' => $name, 'slug' => $slug, 'city' => 'Vancouver',
            'website' => $website, 'is_active' => $active,
        ]);
    }

    private function detect(float $threshold = 0.85): array
    {
        return app(DuplicateRoasterDetector::class)->detect(Roaster::all(), $threshold);
    }

    public function test_canonicalization_strips_industry_filler_and_accents(): void
    {
        $this->assertSame('pilot', DuplicateRoasterDetector::canonicalName('Pilot Coffee Roasters'));
        $this->assertSame('bows arrows', DuplicateRoasterDetector::canonicalName('Bows & Arrows Coffee'));
        $this->assertSame('myriade', DuplicateRoasterDetector::canonicalName('Café Myriade'));
        // A name that is entirely filler collapses to empty (never groups).
        $this->assertSame('', DuplicateRoasterDetector::canonicalName('The Coffee Company'));
    }

    public function test_canonical_host_drops_storefront_subdomains(): void
    {
        $this->assertSame('rosso.com', DuplicateRoasterDetector::canonicalHost('https://www.rosso.com/collections/all'));
        $this->assertSame('rosso.com', DuplicateRoasterDetector::canonicalHost('https://shop.rosso.com'));
        $this->assertNull(DuplicateRoasterDetector::canonicalHost(null));
    }

    public function test_detects_shared_website_host(): void
    {
        // Different names, same host (a rename that forked the row).
        $this->roaster('Old Brand', 'old-brand', 'https://shared.example.com');
        $this->roaster('New Brand', 'new-brand', 'https://www.shared.example.com/shop');

        $result = $this->detect();

        $this->assertCount(1, $result['host_groups']);
        $this->assertCount(2, $result['host_groups'][0]);
        $this->assertSame([], $result['name_groups']);
    }

    public function test_detects_identical_canonical_name_across_hosts(): void
    {
        $this->roaster('Pilot Coffee Roasters', 'pilot-coffee-roasters', 'https://pilotcoffeeroasters.com');
        $this->roaster('Pilot Coffee', 'pilot-coffee', 'https://pilot.example.ca');

        $result = $this->detect();

        $this->assertSame([], $result['host_groups']);
        $this->assertCount(1, $result['name_groups']);
        $this->assertCount(2, $result['name_groups'][0]);
    }

    public function test_flags_similar_names_above_threshold(): void
    {
        $this->roaster('Transcend Coffee', 'transcend', 'https://transcend.example.com');
        $this->roaster('Transend Coffee', 'transend', 'https://transend.example.com');

        $result = $this->detect(0.85);

        $this->assertCount(1, $result['similar_pairs']);
        $this->assertGreaterThanOrEqual(0.85, $result['similar_pairs'][0]['score']);
    }

    public function test_threshold_controls_fuzzy_sensitivity(): void
    {
        // canon "bench" vs "beach": edit distance 1 over length 5 → 0.80.
        $this->roaster('Bench Coffee', 'bench', 'https://bench.example.com');
        $this->roaster('Beach Coffee', 'beach', 'https://beach.example.com');

        $this->assertSame([], $this->detect(0.85)['similar_pairs'], 'excluded at 0.85');
        $this->assertCount(1, $this->detect(0.75)['similar_pairs'], 'included at 0.75');
    }

    public function test_distinct_roasters_produce_no_findings(): void
    {
        $this->roaster('Monogram Coffee', 'monogram', 'https://monogramcoffee.com');
        $this->roaster('Rosso Coffee Roasters', 'rosso', 'https://rossocoffee.com');

        $result = $this->detect();

        $this->assertSame([], $result['host_groups']);
        $this->assertSame([], $result['name_groups']);
        $this->assertSame([], $result['similar_pairs']);
    }

    public function test_command_reports_identical_name_group(): void
    {
        $this->roaster('Pilot Coffee Roasters', 'pilot-coffee-roasters', 'https://pilotcoffeeroasters.com');
        $this->roaster('Pilot Coffee', 'pilot-coffee', 'https://pilot.example.ca');

        $this->artisan('roasters:find-duplicates')
            ->expectsOutputToContain('1 identical-name group(s)')
            ->assertExitCode(0);
    }

    public function test_command_excludes_inactive_unless_flagged(): void
    {
        $this->roaster('Pilot Coffee Roasters', 'pilot-coffee-roasters', 'https://pilotcoffeeroasters.com', true);
        $this->roaster('Pilot Coffee', 'pilot-coffee', 'https://pilot.example.ca', false);

        // Only one active row → nothing to pair.
        $this->artisan('roasters:find-duplicates')
            ->expectsOutputToContain('No likely duplicates')
            ->assertExitCode(0);

        // With the flag the inactive row joins the scan and the pair surfaces.
        $this->artisan('roasters:find-duplicates', ['--include-inactive' => true])
            ->expectsOutputToContain('1 identical-name group(s)')
            ->assertExitCode(0);
    }
}
