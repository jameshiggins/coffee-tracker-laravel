<?php

namespace Tests\Feature;

use App\Models\Coffee;
use App\Models\Roaster;
use App\Models\Tasting;
use App\Models\User;
use App\Services\RoasterImporter;
use App\Services\Scraping\AboutPageScraper;
use App\Services\Scraping\FaviconScraper;
use App\Services\Scraping\ShippingPolicyScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The whole point of Q1+Q2: re-importing a roaster must NOT cascade-delete
 * user tastings. Coffees with stable source ids upsert in place; coffees
 * that disappear from the source get soft-removed (removed_at set) but the
 * row stays so foreign keys hold.
 */
class ImportSoftRemoveTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Importer wired with a no-op about-page scraper so the test fakes don't
     * have to also stub /pages/about + homepage HTTP calls.
     */
    private function importer(): RoasterImporter
    {
        $about = new class extends AboutPageScraper {
            public function fetch(string $url): ?string { return null; }
        };
        // No-op shipping scraper so tests don't have to mock /policies/shipping-policy.
        $shipping = new class extends ShippingPolicyScraper {
            public function fetch(string $url): array {
                return ['shipping_cost' => null, 'free_shipping_over' => null, 'shipping_notes' => null];
            }
        };
        // No-op favicon scraper for the same reason.
        $favicon = new class extends FaviconScraper {
            public function fetch(string $url): ?string { return null; }
        };
        return new RoasterImporter(null, $about, $shipping, $favicon);
    }

    private function shopifyResponse(array $products): array
    {
        return ['products' => $products];
    }

    private function product(int $id, string $title, array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'title' => $title,
            'product_type' => 'Coffee',
            'tags' => [],
            'body_html' => '',
            'handle' => strtolower(str_replace(' ', '-', $title)),
            'variants' => [['id' => $id * 10, 'title' => '340g', 'price' => '24.00', 'available' => true]],
        ], $extra);
    }

    public function test_reimport_preserves_existing_coffee_id_when_source_id_matches(): void
    {
        Http::fake(['*' => Http::response($this->shopifyResponse([
            $this->product(101, 'Ethiopia Yirg'),
        ]), 200)]);

        $importer = $this->importer();
        $roaster = $importer->import('https://example.com', name: 'Example', city: 'Vancouver');
        $firstCoffeeId = $roaster->coffees()->first()->id;

        $importer->import('https://example.com', name: 'Example', city: 'Vancouver');
        $secondCoffeeId = $roaster->fresh()->coffees()->first()->id;

        $this->assertSame($firstCoffeeId, $secondCoffeeId, 'same source_id must keep the same row id');
    }

    public function test_reimport_preserves_user_tastings_across_re_imports(): void
    {
        // The data-integrity invariant Q1+Q2 exists to guarantee.
        Http::fake(['*' => Http::response($this->shopifyResponse([
            $this->product(101, 'Ethiopia Yirg'),
        ]), 200)]);

        $importer = $this->importer();
        $importer->import('https://example.com', name: 'Example', city: 'Vancouver');
        $coffee = Coffee::first();

        $user = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => bcrypt('x')]);
        $tasting = Tasting::create([
            'user_id' => $user->id, 'coffee_id' => $coffee->id,
            'rating' => 8, 'notes' => 'Loved it', 'tasted_on' => '2026-04-01',
        ]);

        // Re-import — new behaviour must NOT cascade-delete this tasting.
        $importer->import('https://example.com', name: 'Example', city: 'Vancouver');

        $this->assertDatabaseHas('tastings', ['id' => $tasting->id, 'coffee_id' => $coffee->id]);
        $this->assertSame(1, Tasting::count());
    }

    public function test_coffee_missing_from_fresh_import_gets_soft_removed(): void
    {
        $importer = $this->importer();

        // Use Http::fakeSequence so each call gets a fresh response.
        // The first import's products.json probe + fetch consume the same
        // response (probe = limit=1, fetch = limit=250); we set per-call.
        Http::fakeSequence()
            // probe + fetch for first import (both hit /products.json*)
            ->push($this->shopifyResponse([$this->product(101, 'Yirg'), $this->product(102, 'House Blend')]), 200)
            ->push($this->shopifyResponse([$this->product(101, 'Yirg'), $this->product(102, 'House Blend')]), 200)
            // the second import already has cached platform=shopify, so only one fetch call
            ->push($this->shopifyResponse([$this->product(101, 'Yirg')]), 200);

        $importer->import('https://example.com', name: 'Example', city: 'Vancouver');
        $importer->import('https://example.com', name: 'Example', city: 'Vancouver');

        $yirg = Coffee::where('source_id', '101')->first();
        $blend = Coffee::where('source_id', '102')->first();
        $this->assertNotNull($blend, 'soft-removed coffee row must still exist');
        $this->assertNotNull($blend->removed_at, 'absent coffee should have removed_at set');
        $this->assertNull($yirg->removed_at, 'still-present coffee should not be soft-removed');
    }

    public function test_soft_removed_coffee_un_removes_when_it_reappears(): void
    {
        $importer = $this->importer();

        Http::fakeSequence()
            ->push($this->shopifyResponse([$this->product(101, 'Yirg')]), 200)  // probe
            ->push($this->shopifyResponse([$this->product(101, 'Yirg')]), 200)  // fetch
            ->push($this->shopifyResponse([]), 200)                             // disappears
            ->push($this->shopifyResponse([$this->product(101, 'Yirg')]), 200); // comes back

        $importer->import('https://example.com', name: 'Example', city: 'Vancouver');
        $importer->import('https://example.com', name: 'Example', city: 'Vancouver');
        $this->assertNotNull(Coffee::where('source_id', '101')->first()->removed_at);

        $importer->import('https://example.com', name: 'Example', city: 'Vancouver');
        $this->assertNull(Coffee::where('source_id', '101')->first()->removed_at,
            'restored coffee should have removed_at cleared');
    }

    public function test_available_scope_excludes_soft_removed(): void
    {
        $roaster = Roaster::create(['name' => 'R', 'slug' => 'r', 'city' => 'V']);
        $live = $roaster->coffees()->create(['name' => 'A', 'origin' => 'Ethiopia']);
        $gone = $roaster->coffees()->create(['name' => 'B', 'origin' => 'Brazil', 'removed_at' => now()]);

        $available = Coffee::available()->pluck('id')->all();
        $this->assertContains($live->id, $available);
        $this->assertNotContains($gone->id, $available);
    }

    public function test_legacy_coffee_with_null_source_id_gets_soft_removed_when_absent_from_reimport(): void
    {
        // Real-world: Oso Negro had a pre-existing "Costa Rican Tarrazú"
        // row imported under a previous code path that didn't populate
        // source_id. The current import returns 17 coffees, none of which
        // are Costa Rican Tarrazú — but because the existing row has
        // source_id=NULL it sidestepped the soft-remove sweep and lingered
        // on the directory indefinitely.
        $roaster = Roaster::create([
            'name' => 'Example', 'slug' => 'example', 'city' => 'Vancouver',
            'website' => 'https://example.com', 'is_active' => true,
        ]);
        $stale = $roaster->coffees()->create([
            'name' => 'Costa Rican Tarrazú',
            'origin' => 'Costa Rica',
            // source_id deliberately null — legacy import shape.
        ]);

        Http::fakeSequence()
            ->push($this->shopifyResponse([$this->product(101, 'Yirg')]), 200)  // probe
            ->push($this->shopifyResponse([$this->product(101, 'Yirg')]), 200); // fetch

        $this->importer()->import('https://example.com', name: 'Example', city: 'Vancouver');

        $stale->refresh();
        $this->assertNotNull($stale->removed_at, 'legacy NULL-source_id coffee should be soft-removed when absent from reimport');
    }

    public function test_coffee_name_html_entities_are_decoded_on_import(): void
    {
        // Real-world: Oso Negro's WC feed returns "P&#038;H&#8217;s
        // Addiction" — the ampersand and curly-apostrophe arrive as raw
        // HTML entities. Without decoding at import time those entities
        // get persisted as-is and render literally in the UI ("P&#038;H").
        Http::fake(['*' => Http::response($this->shopifyResponse([
            $this->product(101, 'P&#038;H&#8217;s Addiction'),
            $this->product(102, 'Bows &amp; Arrows'),
        ]), 200)]);

        $this->importer()->import('https://example.com', name: 'Example', city: 'Vancouver');

        $names = Coffee::pluck('name')->all();
        $this->assertContains("P&H's Addiction", $names, 'numeric entities &#038; and &#8217; must decode');
        $this->assertContains('Bows & Arrows', $names, 'named entity &amp; must decode');
    }

    public function test_legacy_null_source_id_coffee_is_matched_by_name_and_not_duplicated(): void
    {
        // If a current import happens to include a coffee whose name
        // matches a pre-existing NULL-source_id row, the importer must
        // re-bind to it (not duplicate). The newly-imported row gets the
        // proper source_id so future runs use the cheap source_id path.
        $roaster = Roaster::create([
            'name' => 'Example', 'slug' => 'example', 'city' => 'Vancouver',
            'website' => 'https://example.com', 'is_active' => true,
        ]);
        $legacy = $roaster->coffees()->create([
            'name' => 'Ethiopia Yirg', 'origin' => 'Ethiopia',
        ]);
        $legacyId = $legacy->id;

        Http::fakeSequence()
            ->push($this->shopifyResponse([$this->product(101, 'Ethiopia Yirg')]), 200)
            ->push($this->shopifyResponse([$this->product(101, 'Ethiopia Yirg')]), 200);

        $this->importer()->import('https://example.com', name: 'Example', city: 'Vancouver');

        $this->assertSame(1, Coffee::where('roaster_id', $roaster->id)->count(),
            'name match must re-bind to legacy row, not create a duplicate');
        $rebound = Coffee::where('roaster_id', $roaster->id)->first();
        $this->assertSame($legacyId, $rebound->id, 'must reuse the existing row id');
        $this->assertSame('101', $rebound->source_id, 'should backfill source_id from the fresh import');
        $this->assertNull($rebound->removed_at, 'matched row must not be soft-removed');
    }
}
