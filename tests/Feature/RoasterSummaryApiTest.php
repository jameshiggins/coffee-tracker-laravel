<?php

namespace Tests\Feature;

use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/roasters/summary — the slim landing-page payload (2026-07 review):
 * roaster scalars + precomputed aggregates, NO nested coffees/variants. The
 * aggregates must mirror what the SPA previously computed client-side from the
 * full tree (isCoffeeInStock, priceRange, bean-name search).
 */
class RoasterSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedRoaster(): Roaster
    {
        $r = Roaster::create([
            'name' => 'Aroma', 'slug' => 'aroma', 'city' => 'Vancouver', 'region' => 'BC',
            'website' => 'https://aroma.example.com', 'is_active' => true, 'has_shipping' => true,
        ]);

        // Coffee A: one in-stock 250g/$20 (8¢/g) + one OOS 1000g/$50 (5¢/g).
        $a = $r->coffees()->create(['name' => 'Yirgacheffe Konga', 'origin' => 'Ethiopia']);
        $a->variants()->create(['bag_weight_grams' => 250, 'price' => 20, 'in_stock' => true]);
        $a->variants()->create(['bag_weight_grams' => 1000, 'price' => 50, 'in_stock' => false]);

        // Coffee B: fully out of stock — 500g/$30 (6¢/g).
        $b = $r->coffees()->create(['name' => 'Huila', 'origin' => 'Colombia']);
        $b->variants()->create(['bag_weight_grams' => 500, 'price' => 30, 'in_stock' => false]);

        // Weightless variant: excluded from every ¢/g rollup.
        $b->variants()->create(['bag_weight_grams' => 0, 'price' => 15, 'in_stock' => true]);

        // Soft-removed coffee: excluded from everything.
        $r->coffees()->create(['name' => 'Old Gone Bean', 'origin' => 'Kenya', 'removed_at' => now()]);

        return $r;
    }

    public function test_summary_returns_aggregates_without_the_coffee_tree(): void
    {
        $this->seedRoaster();

        $res = $this->getJson('/api/roasters/summary')->assertOk();
        $row = collect($res->json('roasters'))->firstWhere('slug', 'aroma');

        $this->assertNotNull($row);
        $this->assertArrayNotHasKey('coffees', $row, 'the slim payload must not ship the coffee tree');

        $this->assertSame(2, $row['coffees_count'], 'removed coffee excluded');
        // Coffee B's weightless variant is in stock, so B counts as buyable —
        // matching the SPA's isCoffeeInStock (any in-stock variant).
        $this->assertSame(2, $row['in_stock_count']);
        $this->assertSame(3 + 1, $row['variants_count']);

        // ¢/g: in-stock weighable = only A's 250g/$20 → 8.0; all = {8, 5, 6}.
        // assertEqualsWithDelta: json round-trips whole floats as ints (8.0→8).
        $this->assertEqualsWithDelta(8.0, $row['cpg_min'], 0.001);
        $this->assertEqualsWithDelta(8.0, $row['cpg_max'], 0.001);
        $this->assertEqualsWithDelta(5.0, $row['cpg_min_all'], 0.001);
        $this->assertEqualsWithDelta(8.0, $row['cpg_max_all'], 0.001);
    }

    public function test_search_terms_carry_bean_names_and_origins_lowercased(): void
    {
        $this->seedRoaster();

        $row = collect($this->getJson('/api/roasters/summary')->json('roasters'))
            ->firstWhere('slug', 'aroma');

        $this->assertStringContainsString('yirgacheffe konga', $row['search_terms']);
        $this->assertStringContainsString('ethiopia', $row['search_terms']);
        $this->assertStringContainsString('colombia', $row['search_terms']);
        $this->assertStringNotContainsString('old gone bean', $row['search_terms'], 'removed coffees excluded');
    }

    public function test_inactive_roasters_are_excluded(): void
    {
        $this->seedRoaster();
        Roaster::create([
            'name' => 'Hidden', 'slug' => 'hidden', 'city' => 'X',
            'website' => 'https://hidden.example.com', 'is_active' => false,
        ]);

        $slugs = collect($this->getJson('/api/roasters/summary')->json('roasters'))->pluck('slug');

        $this->assertContains('aroma', $slugs);
        $this->assertNotContains('hidden', $slugs);
    }

    public function test_scalar_fields_match_the_full_index_payload(): void
    {
        $this->seedRoaster();

        $full = collect($this->getJson('/api/roasters')->json('roasters'))->firstWhere('slug', 'aroma');
        $slim = collect($this->getJson('/api/roasters/summary')->json('roasters'))->firstWhere('slug', 'aroma');

        foreach (['id', 'slug', 'name', 'city', 'region', 'website', 'favicon_url',
                  'has_shipping', 'shipping_cost', 'free_shipping_over', 'is_online_only',
                  'latitude', 'longitude', 'last_import_status'] as $field) {
            $this->assertSame($full[$field], $slim[$field], "field {$field} drifted between payloads");
        }
        // The rollups the SPA previously derived client-side agree too.
        $this->assertSame($full['coffees_count'], $slim['coffees_count']);
        $this->assertSame($full['variants_count'], $slim['variants_count']);
    }

    public function test_etag_revalidation_returns_304(): void
    {
        $this->seedRoaster();

        $first = $this->getJson('/api/roasters/summary')->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->withHeaders(['If-None-Match' => $etag])
            ->getJson('/api/roasters/summary')
            ->assertStatus(304);
    }
}
