<?php

namespace Tests\Feature;

use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoasterApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoaster(array $overrides = []): Roaster
    {
        $roaster = Roaster::create(array_merge([
            'name' => 'Test Roaster',
            'slug' => 'test-roaster',
            'city' => 'Vancouver',
            'region' => 'Vancouver',
            'website' => 'https://example.com',
            'has_shipping' => true,
            'is_active' => true,
            'latitude' => 49.2827,
            'longitude' => -123.1207,
        ], $overrides));

        $coffee = $roaster->coffees()->create([
            'name' => 'Yirgacheffe',
            'origin' => 'Ethiopia, Yirgacheffe',
            'process' => 'washed',
            'roast_level' => 'light',
            'tasting_notes' => 'Floral, citrus',
        ]);

        $coffee->variants()->create([
            'bag_weight_grams' => 250,
            'price' => 24.00,
            'in_stock' => true,
        ]);
        $coffee->variants()->create([
            'bag_weight_grams' => 340,
            'price' => 30.00,
            'in_stock' => true,
        ]);

        return $roaster;
    }

    public function test_index_returns_only_active_roasters(): void
    {
        $this->seedRoaster();
        Roaster::create(['name' => 'Inactive', 'slug' => 'inactive', 'city' => 'Vancouver', 'is_active' => false]);

        $response = $this->getJson('/api/roasters');

        $response->assertOk();
        $this->assertCount(1, $response->json('roasters'));
        $this->assertSame('Test Roaster', $response->json('roasters.0.name'));
    }

    public function test_index_returns_variants_with_per_gram_calculations(): void
    {
        $this->seedRoaster();

        $response = $this->getJson('/api/roasters');
        $variants = $response->json('roasters.0.coffees.0.variants');

        $this->assertCount(2, $variants);
        $this->assertSame(250, $variants[0]['bag_weight_grams']);
        $this->assertSame(0.096, $variants[0]['price_per_gram']);
        $this->assertSame(9.6, $variants[0]['cents_per_gram']);
    }

    public function test_index_exposes_default_variant_as_smallest_in_stock(): void
    {
        $this->seedRoaster();

        $coffee = $this->getJson('/api/roasters')->json('roasters.0.coffees.0');

        // First variant in stock — variants are ordered by grams ascending,
        // so this is the 250g.
        $this->assertSame(250, $coffee['default_variant']['bag_weight_grams']);
        $this->assertTrue($coffee['default_variant']['in_stock']);
    }

    public function test_show_returns_full_roaster_with_address_fields(): void
    {
        $this->seedRoaster([
            'street_address' => '123 Main St',
            'postal_code' => 'V6B 1A1',
            'description' => 'A great roaster',
        ]);

        $response = $this->getJson('/api/roasters/test-roaster');

        $response->assertOk()
            ->assertJsonPath('street_address', '123 Main St')
            ->assertJsonPath('postal_code', 'V6B 1A1')
            ->assertJsonPath('latitude', 49.2827)
            ->assertJsonPath('longitude', -123.1207)
            ->assertJsonPath('description', 'A great roaster');
    }

    public function test_show_returns_404_for_unknown_slug(): void
    {
        $this->seedRoaster();
        $this->getJson('/api/roasters/does-not-exist')->assertNotFound();
    }

    public function test_index_exposes_is_online_only_and_address_source(): void
    {
        // Resolved through JSON-LD on the roaster's own site.
        $this->seedRoaster([
            'slug' => 'jsonld-shop',
            'name' => 'JSON-LD Shop',
            'address_source' => 'jsonld',
            'is_online_only' => false,
        ]);
        // Cascade exhausted every step — online-only operation, no map pin.
        $this->seedRoaster([
            'slug' => 'online-only-shop',
            'name' => 'Online Only Shop',
            'address_source' => null,
            'is_online_only' => true,
        ]);

        $response = $this->getJson('/api/roasters')->assertOk();
        $byName = collect($response->json('roasters'))->keyBy('name');

        $this->assertSame('jsonld', $byName['JSON-LD Shop']['address_source']);
        $this->assertFalse($byName['JSON-LD Shop']['is_online_only']);

        $this->assertNull($byName['Online Only Shop']['address_source']);
        $this->assertTrue($byName['Online Only Shop']['is_online_only']);
    }

    public function test_show_exposes_is_online_only_and_address_source(): void
    {
        $this->seedRoaster([
            'address_source' => 'osm',
            'is_online_only' => false,
        ]);

        $this->getJson('/api/roasters/test-roaster')
            ->assertOk()
            ->assertJsonPath('address_source', 'osm')
            ->assertJsonPath('is_online_only', false);
    }

    public function test_index_exposes_import_freshness(): void
    {
        $seeded = $this->seedRoaster([
            'last_import_status' => 'success',
            'last_imported_at' => now()->subDay(),
        ]);
        // Re-read through the model so the assertion uses the same timezone
        // normalization the API does, rather than assuming UTC.
        $expected = $seeded->fresh()->last_imported_at->toIso8601String();

        $roaster = $this->getJson('/api/roasters')->assertOk()->json('roasters.0');

        $this->assertSame('success', $roaster['last_import_status']);
        $this->assertSame($expected, $roaster['last_imported_at']);
    }

    public function test_freshness_is_null_when_never_imported(): void
    {
        $this->seedRoaster(); // default seed leaves import columns null

        $roaster = $this->getJson('/api/roasters')->assertOk()->json('roasters.0');

        $this->assertNull($roaster['last_imported_at']);
        $this->assertNull($roaster['last_import_status']);
    }

    public function test_stats_returns_coverage_and_freshness_summary(): void
    {
        // fresh + located
        $this->seedRoaster(['slug' => 'r1', 'name' => 'R1',
            'last_import_status' => 'success', 'last_imported_at' => now()->subDay()]);
        // stale (old success) + located
        $this->seedRoaster(['slug' => 'r2', 'name' => 'R2',
            'last_import_status' => 'success', 'last_imported_at' => now()->subDays(30)]);
        // never imported + unplaced (no coords, not online-only)
        $this->seedRoaster(['slug' => 'r3', 'name' => 'R3',
            'latitude' => null, 'longitude' => null]);
        // online-only (no pin expected) + fresh
        $this->seedRoaster(['slug' => 'r4', 'name' => 'R4',
            'is_online_only' => true, 'latitude' => null, 'longitude' => null,
            'last_import_status' => 'success', 'last_imported_at' => now()->subDay()]);
        // inactive — excluded from every count
        Roaster::create(['name' => 'Off', 'slug' => 'off', 'city' => 'Vancouver', 'is_active' => false]);

        $stats = $this->getJson('/api/stats')->assertOk()->json();

        $this->assertSame(4, $stats['roasters_total']);
        $this->assertSame(4, $stats['coffees_total']);
        $this->assertNotNull($stats['last_imported_at']);

        $this->assertSame(2, $stats['freshness']['fresh']);
        $this->assertSame(1, $stats['freshness']['stale']);
        $this->assertSame(1, $stats['freshness']['never']);
        $this->assertSame(7, $stats['freshness']['fresh_within_days']);

        $this->assertSame(2, $stats['map_coverage']['located']);
        $this->assertSame(1, $stats['map_coverage']['online_only']);
        $this->assertSame(1, $stats['map_coverage']['unplaced']);
    }

}
