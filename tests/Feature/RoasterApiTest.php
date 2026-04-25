<?php

namespace Tests\Feature;

use App\Models\PriceHistory;
use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
            'is_default' => false,
        ]);
        $coffee->variants()->create([
            'bag_weight_grams' => 340,
            'price' => 30.00,
            'in_stock' => true,
            'is_default' => true,
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

    public function test_index_marks_default_variant_and_exposes_it_at_coffee_level(): void
    {
        $this->seedRoaster();

        $coffee = $this->getJson('/api/roasters')->json('roasters.0.coffees.0');

        $this->assertSame(340, $coffee['default_variant']['bag_weight_grams']);
        $this->assertTrue($coffee['default_variant']['is_default']);
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

    public function test_variant_history_returns_chronological_price_points(): void
    {
        $roaster = $this->seedRoaster();
        $variant = $roaster->coffees->first()->variants->first();

        $now = Carbon::parse('2026-04-24 12:00:00');
        PriceHistory::insert([
            ['coffee_variant_id' => $variant->id, 'price' => 25.50, 'in_stock' => true,
                'recorded_at' => $now->copy()->subDays(7), 'created_at' => $now, 'updated_at' => $now],
            ['coffee_variant_id' => $variant->id, 'price' => 24.25, 'in_stock' => true,
                'recorded_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $response = $this->getJson("/api/variants/{$variant->id}/history");

        $response->assertOk();
        $history = $response->json('history');
        $this->assertCount(2, $history);
        $this->assertSame(25.50, $history[0]['price']);
        $this->assertSame(24.25, $history[1]['price']);
        $this->assertTrue($history[0]['recorded_at'] < $history[1]['recorded_at']);
    }

    public function test_variant_history_404_for_unknown_variant(): void
    {
        $this->getJson('/api/variants/9999/history')->assertNotFound();
    }
}
