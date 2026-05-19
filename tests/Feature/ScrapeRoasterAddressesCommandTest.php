<?php

namespace Tests\Feature;

use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Q-AR: artisan command that walks every active roaster and runs the
 * address-resolution cascade against it. Verifies idempotency
 * (already-resolved roasters are skipped), --force, --limit, --only, and
 * the is_online_only fallback when every step exhausts.
 */
class ScrapeRoasterAddressesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google_places.key' => null]);
    }

    private function jsonLdHtml(string $street): string
    {
        return '<html><head><script type="application/ld+json">'
            . json_encode([
                '@type' => 'LocalBusiness',
                'name' => 'X',
                'address' => [
                    'streetAddress' => $street,
                    'addressLocality' => 'Vancouver',
                    'addressRegion' => 'BC',
                    'postalCode' => 'V6B 1A1',
                ],
            ])
            . '</script></head></html>';
    }

    private function makeRoaster(string $name, string $slug, string $host, array $extra = []): Roaster
    {
        return Roaster::create(array_merge([
            'name' => $name, 'slug' => $slug, 'city' => 'Vancouver', 'region' => 'BC',
            'country_code' => 'CA', 'website' => "https://{$host}", 'is_active' => true,
        ], $extra));
    }

    public function test_command_persists_jsonld_hit_and_stamps_source_and_timestamp(): void
    {
        $this->makeRoaster('Alpha', 'alpha', 'alpha.example.com');

        Http::fake([
            '*nominatim*' => Http::response([['lat' => '49.1', 'lon' => '-123.1']], 200),
            '*alpha.example.com*' => Http::response($this->jsonLdHtml('100 Alpha St'), 200),
        ]);

        $this->artisan('roasters:scrape-addresses')->assertExitCode(0);

        $alpha = Roaster::where('slug', 'alpha')->first();
        $this->assertSame('jsonld', $alpha->address_source);
        $this->assertSame('100 Alpha St', $alpha->street_address);
        $this->assertSame('V6B 1A1', $alpha->postal_code);
        $this->assertNotNull($alpha->address_verified_at);
        $this->assertFalse($alpha->is_online_only);
        // Step 5 geocoding fills lat/lng from Nominatim.
        $this->assertSame(49.1, (float) $alpha->latitude);
        $this->assertSame(-123.1, (float) $alpha->longitude);
    }

    public function test_command_marks_online_only_when_cascade_exhausts(): void
    {
        $this->makeRoaster('NoLuck', 'no-luck', 'noluck.example.com');

        Http::fake([
            '*nominatim*' => Http::response([], 200),
            '*' => Http::response('<html><body>nothing</body></html>', 200),
        ]);

        $this->artisan('roasters:scrape-addresses')->assertExitCode(0);

        $r = Roaster::where('slug', 'no-luck')->first();
        $this->assertTrue($r->is_online_only);
        $this->assertNull($r->address_source);
        $this->assertNotNull($r->address_verified_at);
    }

    public function test_command_is_idempotent_by_default(): void
    {
        // Already resolved — should NOT hit any HTTP.
        $this->makeRoaster('Done', 'done', 'done.example.com', [
            'address_source' => 'manual',
            'street_address' => '999 Already Done',
            'address_verified_at' => now()->subDays(5),
        ]);

        Http::fake();

        $this->artisan('roasters:scrape-addresses')->assertExitCode(0);

        $r = Roaster::where('slug', 'done')->first();
        $this->assertSame('manual', $r->address_source);
        Http::assertNothingSent();
    }

    public function test_force_flag_reprocesses_resolved_roasters(): void
    {
        $this->makeRoaster('Force Me', 'force-me', 'forceme.example.com', [
            'address_source' => 'manual',
            'street_address' => '999 Old Address',
        ]);

        Http::fake([
            '*nominatim*' => Http::response([['lat' => '49.2', 'lon' => '-123.2']], 200),
            '*forceme.example.com*' => Http::response($this->jsonLdHtml('100 New St'), 200),
        ]);

        $this->artisan('roasters:scrape-addresses', ['--force' => true])->assertExitCode(0);

        $r = Roaster::where('slug', 'force-me')->first();
        $this->assertSame('jsonld', $r->address_source);
        $this->assertSame('100 New St', $r->street_address);
    }

    public function test_limit_caps_the_number_processed(): void
    {
        $this->makeRoaster('One', 'one', 'one.example.com');
        $this->makeRoaster('Two', 'two', 'two.example.com');
        $this->makeRoaster('Three', 'three', 'three.example.com');

        Http::fake([
            '*nominatim*' => Http::response([], 200),
            '*' => Http::response('<html></html>', 200),
        ]);

        $this->artisan('roasters:scrape-addresses', ['--limit' => 1])->assertExitCode(0);

        // Only one should have been touched (any of them — the implementation
        // is free to pick any ordering, we just check the cardinality).
        $touched = Roaster::whereNotNull('address_verified_at')->count();
        $this->assertSame(1, $touched);
    }

    public function test_only_flag_targets_a_single_roaster_by_slug(): void
    {
        $this->makeRoaster('Wanted', 'wanted', 'wanted.example.com');
        $this->makeRoaster('Other', 'other', 'other.example.com');

        Http::fake([
            '*nominatim*' => Http::response([['lat' => '49.0', 'lon' => '-123.0']], 200),
            '*wanted.example.com*' => Http::response($this->jsonLdHtml('500 Target Ave'), 200),
            '*other.example.com*' => Http::response($this->jsonLdHtml('999 Skipped St'), 200),
        ]);

        $this->artisan('roasters:scrape-addresses', ['--only' => 'wanted'])->assertExitCode(0);

        $this->assertSame('500 Target Ave', Roaster::where('slug', 'wanted')->value('street_address'));
        $this->assertNull(Roaster::where('slug', 'other')->value('address_source'));
    }

    public function test_only_flag_works_with_name_as_well_as_slug(): void
    {
        $this->makeRoaster('Wanted Roaster', 'wanted', 'wanted.example.com');

        Http::fake([
            '*nominatim*' => Http::response([['lat' => '49.0', 'lon' => '-123.0']], 200),
            '*wanted.example.com*' => Http::response($this->jsonLdHtml('500 By Name'), 200),
        ]);

        $this->artisan('roasters:scrape-addresses', ['--only' => 'Wanted Roaster'])->assertExitCode(0);

        $this->assertSame('500 By Name', Roaster::where('slug', 'wanted')->value('street_address'));
    }
}
