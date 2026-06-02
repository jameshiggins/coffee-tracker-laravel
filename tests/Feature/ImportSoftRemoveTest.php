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

        // Scope the sequence to /products.json so metafield-enrichment's
        // per-product page fetches (the product() helper uses an empty
        // body_html, which is "thin" and triggers an enrichment fetch) draw
        // from the '*' fallback instead of stealing products.json responses.
        // products.json draws: import1 probe(limit=1) + fetch(limit=250),
        // then import2 fetch only (platform is cached so no re-probe).
        Http::fake([
            '*/products.json*' => Http::sequence()
                ->push($this->shopifyResponse([$this->product(101, 'Yirg'), $this->product(102, 'House Blend')]), 200)
                ->push($this->shopifyResponse([$this->product(101, 'Yirg'), $this->product(102, 'House Blend')]), 200)
                ->push($this->shopifyResponse([$this->product(101, 'Yirg')]), 200),
            '*' => Http::response('', 200), // product pages → enrichment no-op
        ]);

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

        // We use a different second coffee (102) on the "disappears" run so
        // the fetch is non-empty — that bypasses the empty-fetch safety
        // check and lets the soft-remove logic actually fire on coffee 101.
        // Then the third run brings 101 back and it should un-remove.
        // Scope to /products.json so enrichment's product-page fetches use
        // the '*' fallback. products.json draws: import1 probe+fetch, then
        // import2 fetch and import3 fetch (platform cached → no re-probe).
        Http::fake([
            '*/products.json*' => Http::sequence()
                ->push($this->shopifyResponse([$this->product(101, 'Yirg')]), 200)  // probe
                ->push($this->shopifyResponse([$this->product(101, 'Yirg')]), 200)  // fetch
                ->push($this->shopifyResponse([$this->product(102, 'Other')]), 200) // 101 absent (102 takes its place) → 101 soft-removes
                ->push($this->shopifyResponse([$this->product(101, 'Yirg')]), 200), // 101 returns
            '*' => Http::response('', 200), // product pages → enrichment no-op
        ]);

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

    public function test_zero_coffee_import_does_not_wipe_existing_catalog(): void
    {
        // REGRESSION: my soft-remove refactor (acd17ce predecessor) tracks
        // touchedIds across ALL existing coffees and soft-removes anything
        // not touched. If a re-import returns 0 coffees — common when a
        // scraper transiently fails, the site rate-limits, or a platform
        // shape shifts mid-day — that means $touchedIds is empty and ALL
        // existing coffees get soft-removed in one sweep. A scraper hiccup
        // shouldn't wipe a roaster's entire catalog.
        $importer = $this->importer();

        // Seed with 2 coffees from a healthy first import. Scope to
        // /products.json so enrichment's product-page fetches use the '*'
        // fallback. products.json draws: import1 probe+fetch, import2 fetch.
        Http::fake([
            '*/products.json*' => Http::sequence()
                ->push($this->shopifyResponse([
                    $this->product(101, 'Yirg'),
                    $this->product(102, 'House Blend'),
                ]), 200)
                ->push($this->shopifyResponse([
                    $this->product(101, 'Yirg'),
                    $this->product(102, 'House Blend'),
                ]), 200)
                // Second import: scraper returns empty — site rate-limited
                // or transient platform-shape hiccup. Must NOT wipe the catalog.
                ->push($this->shopifyResponse([]), 200),
            '*' => Http::response('', 200), // product pages → enrichment no-op
        ]);

        $importer->import('https://example.com', name: 'Example', city: 'Vancouver');
        $this->assertSame(2, Coffee::available()->count(), 'first import seeds 2 coffees');

        $importer->import('https://example.com', name: 'Example', city: 'Vancouver');

        $this->assertSame(2, Coffee::available()->count(),
            'empty import must NOT soft-remove the existing catalog');
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

    public function test_text_fields_get_sanitized_on_import(): void
    {
        // Audit on the live API showed: tasting_notes with leading U+FFFD
        // (Botany Rd), "&amp;" still encoded (Milano Coffee), curly quotes
        // mixed with ASCII (Rooftop's "Nari&ntilde;o" → should become Nariño),
        // and multi-space artifacts. sanitizeText fixes all of these in
        // one pass. The product is fed straight through ShopifyScraper
        // so we set tasting_notes on the variant input as if it came back
        // from the scraper's normalize() output — simpler than mocking
        // a full Shopify body_html.
        $product = array_merge($this->product(500, 'Test Coffee'), [
            // Use the live-data shape: HTTP feed has &amp; encoded once.
            // After import, the decoded text should be just "& juicy".
            'body_html' => '<p>Tasting notes: Cherry, grape, chocolate, sweet &amp; juicy</p>',
        ]);
        Http::fake(['*' => Http::response($this->shopifyResponse([$product]), 200)]);

        $this->importer()->import('https://example.com', name: 'Example', city: 'Vancouver');

        $c = Coffee::where('source_id', '500')->first();
        $this->assertNotNull($c);
        if ($c->tasting_notes) {
            $this->assertStringNotContainsString('&amp;', $c->tasting_notes,
                'tasting_notes must have entities decoded');
            $this->assertStringContainsString('&', $c->tasting_notes,
                'the decoded ampersand itself should be present');
        }
    }

    public function test_text_fields_get_trimmed_and_multi_space_collapsed(): void
    {
        // Direct sanitize check on a product whose tasting_notes are
        // explicitly set in the scraper output. We bypass the scraper
        // and call upsertCoffee-equivalent via the import flow by
        // crafting a product whose body_html (via the Shopify scraper)
        // produces dirty fields. Easier: just craft a product where
        // body_html ends up as tasting_notes via the field extractor.
        $body = '<p>Notes: &nbsp; Cherry,  grape,   chocolate &amp; sweet  </p>';
        $product = array_merge($this->product(501, 'Dirty Notes'), [
            'body_html' => $body,
        ]);
        Http::fake(['*' => Http::response($this->shopifyResponse([$product]), 200)]);

        $this->importer()->import('https://example.com', name: 'Example', city: 'Vancouver');

        $c = Coffee::where('source_id', '501')->first();
        $this->assertNotNull($c);
        $notes = $c->tasting_notes;
        if ($notes !== null) {
            $this->assertSame($notes, trim($notes),
                'tasting_notes must not have leading/trailing whitespace');
            $this->assertStringNotContainsString('  ', $notes,
                'tasting_notes must not contain double spaces');
            $this->assertStringNotContainsString('&amp;', $notes,
                'tasting_notes must not contain encoded HTML entities');
            $this->assertStringNotContainsString('&nbsp;', $notes,
                'tasting_notes must have nbsp normalized to a regular space');
        }
    }

    public function test_coffee_name_collapses_double_spaces(): void
    {
        // Real-world: Rogue Wave's catalog has titles like
        // "Brazil  - Daterra Low Caf Reserve" — note the double space
        // after "Brazil". The audit found 31 such names. sanitizeText
        // collapses runs to single spaces. (Avoid trailing process
        // words like "Pulped Natural" in the test fixture because
        // cleanCoffeeName strips those by design.)
        Http::fake(['*' => Http::response($this->shopifyResponse([
            $this->product(601, 'Brazil  - Daterra  Reserve'),
        ]), 200)]);

        $this->importer()->import('https://example.com', name: 'Example', city: 'Vancouver');

        $names = Coffee::pluck('name')->all();
        $this->assertContains('Brazil - Daterra Reserve', $names);
        foreach ($names as $n) {
            $this->assertStringNotContainsString('  ', $n,
                "coffee name '$n' must not have double spaces");
        }
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
