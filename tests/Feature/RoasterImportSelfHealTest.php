<?php

namespace Tests\Feature;

use App\Models\Roaster;
use App\Services\RoasterImporter;
use App\Services\Scraping\RoasterScraper;
use App\Services\Scraping\ScraperRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Covers the self-healing platform re-detection added to
 * RoasterImporter::attemptRedetect(). The motivating bug: Prototype's
 * roasters.platform was cached as 'generic' (so every import dispatched the
 * GenericHtmlScraper and returned zero coffees) even though the site is really
 * Squarespace. A stale cache should self-correct toward a confident,
 * more-specific platform that actually returns a catalog — but must NOT switch
 * to 'generic' (the catch-all whose canHandle() is always true), which would
 * erase a good cached platform during a transient platform outage.
 */
class RoasterImportSelfHealTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A configurable fake scraper. `$fetchResult` may be an array (returned) or
     * a Throwable (thrown), letting one helper model "fetches empty",
     * "fetches a catalog", and "hard-errors".
     */
    private function fakeScraper(string $key, bool $canHandle, array|\Throwable $fetchResult): RoasterScraper
    {
        return new class($key, $canHandle, $fetchResult) implements RoasterScraper {
            public function __construct(
                private string $key,
                private bool $can,
                private array|\Throwable $fetchResult,
            ) {}

            public function canHandle(string $url): bool
            {
                return $this->can;
            }

            public function fetch(string $url): array
            {
                if ($this->fetchResult instanceof \Throwable) {
                    throw $this->fetchResult;
                }
                return $this->fetchResult;
            }

            public function platformKey(): string
            {
                return $this->key;
            }
        };
    }

    /** One normalized coffee row in the shape scrapers hand to the importer. */
    private function oneCoffee(): array
    {
        return [[
            'name' => 'Bohemia (Washed Gesha), Colombia',
            'source_id' => 'p1',
            'description' => '100g. Tasting Notes: Earl Grey, Green Apple.',
            'image_url' => null,
            'product_url' => 'https://prototypecoffee.test/shop/bohemia',
            'is_blend' => false,
            'variants' => [
                ['grams' => 100, 'price' => 29.0, 'available' => true, 'source_id' => 'v1'],
            ],
        ]];
    }

    public function test_empty_fetch_from_stale_generic_cache_heals_to_specific_platform(): void
    {
        Http::fake(['*' => Http::response('', 200)]); // about/favicon/shipping best-effort

        // Stale cache: marked 'generic' with an empty catalog (the Prototype shape).
        Roaster::create([
            'slug' => 'prototype', 'name' => 'Prototype', 'city' => 'Vancouver',
            'website' => 'https://prototypecoffee.test', 'platform' => 'generic',
            'is_active' => true, 'has_shipping' => true,
        ]);

        // Fresh probe order: squarespace fake (canHandle) wins before generic.
        $registry = new ScraperRegistry([
            $this->fakeScraper('squarespace', true, $this->oneCoffee()),
            $this->fakeScraper('generic', true, []),
        ]);

        $roaster = (new RoasterImporter($registry))
            ->import('https://prototypecoffee.test', name: 'Prototype', city: 'Vancouver');

        $this->assertSame('squarespace', $roaster->platform, 'stale generic cache heals to the real platform');
        $this->assertSame(1, $roaster->coffees()->count(), 'the healed catalog imports');
        $this->assertSame('success', $roaster->fresh()->last_import_status);
    }

    public function test_hard_error_from_stale_cache_heals_to_specific_platform(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        Roaster::create([
            'slug' => 'prototype', 'name' => 'Prototype', 'city' => 'Vancouver',
            'website' => 'https://prototypecoffee.test', 'platform' => 'shopify',
            'is_active' => true, 'has_shipping' => true,
        ]);

        // Cached 'shopify' now hard-errors (e.g. /products.json 404 post-migration);
        // a fresh probe finds Squarespace, which returns a real catalog.
        $registry = new ScraperRegistry([
            $this->fakeScraper('squarespace', true, $this->oneCoffee()),
            $this->fakeScraper('shopify', false, new RuntimeException('products.json 404')),
            $this->fakeScraper('generic', true, []),
        ]);

        $roaster = (new RoasterImporter($registry))
            ->import('https://prototypecoffee.test', name: 'Prototype', city: 'Vancouver');

        $this->assertSame('squarespace', $roaster->platform, 'erroring cache heals to the real platform');
        $this->assertSame(1, $roaster->coffees()->count());
        $this->assertSame('success', $roaster->fresh()->last_import_status);
    }

    public function test_empty_fetch_does_not_switch_to_generic_and_preserves_catalog(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        // Cached 'shopify' with an EXISTING catalog. A transient empty poll must
        // neither rewrite the platform to the catch-all 'generic' nor wipe the
        // catalog (the syncCoffees empty-fetch safety guard).
        $roaster = Roaster::create([
            'slug' => 'established', 'name' => 'Established', 'city' => 'Toronto',
            'website' => 'https://established.test', 'platform' => 'shopify',
            'is_active' => true, 'has_shipping' => true,
        ]);
        $coffee = $roaster->coffees()->create(['name' => 'Existing Bean', 'source_id' => 'x1', 'origin' => 'Colombia']);
        $coffee->variants()->create(['bag_weight_grams' => 250, 'price' => 24.0, 'in_stock' => true]);

        // Cached shopify fetches empty; the only fresh match is the generic
        // catch-all — which the guard refuses to adopt.
        $registry = new ScraperRegistry([
            $this->fakeScraper('shopify', false, []),
            $this->fakeScraper('generic', true, []),
        ]);

        $result = (new RoasterImporter($registry))
            ->import('https://established.test', name: 'Established', city: 'Toronto');

        $this->assertSame('shopify', $result->platform, 'must NOT heal toward the generic catch-all');
        $this->assertSame('empty', $result->fresh()->last_import_status);
        $this->assertSame(1, $result->coffees()->whereNull('removed_at')->count(), 'transient empty poll must not wipe the catalog');
    }

    public function test_first_import_with_no_cache_does_not_invoke_redetect(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        // No pre-existing roaster → platform is null on first run. A fresh
        // detect already ran inside import(); the empty-fetch heal block is
        // gated on a truthy cached platform, so it must stay dormant here.
        $registry = new ScraperRegistry([
            $this->fakeScraper('squarespace', true, $this->oneCoffee()),
            $this->fakeScraper('generic', true, []),
        ]);

        $roaster = (new RoasterImporter($registry))
            ->import('https://brandnew.test', name: 'Brand New', city: 'Calgary');

        // squarespace is the first canHandle() match, so a clean first import
        // lands there directly (no heal needed).
        $this->assertSame('squarespace', $roaster->platform);
        $this->assertSame(1, $roaster->coffees()->count());
        $this->assertSame('success', $roaster->fresh()->last_import_status);
    }
}
