<?php

namespace Tests\Feature;

use App\Models\Coffee;
use App\Services\RoasterImporter;
use App\Services\Scraping\RoasterScraper;
use App\Services\Scraping\ScraperRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The scraper contract (docs/developer-guide.md) documents an 'origin' key,
 * but the importer used to overwrite it unconditionally with title inference
 * (2026-07 review P3) — a scraper emitting structured origin data had it
 * silently discarded. Provided origin wins; inference is the fallback.
 */
class ImporterOriginContractTest extends TestCase
{
    use RefreshDatabase;

    private function fakeScraper(array $coffees): RoasterScraper
    {
        return new class($coffees) implements RoasterScraper {
            public function __construct(private array $coffees) {}
            public function canHandle(string $url): bool { return true; }
            public function fetch(string $url): array { return $this->coffees; }
            public function platformKey(): string { return 'shopify'; }
        };
    }

    public function test_scraper_provided_origin_wins_over_title_inference(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $variant = ['grams' => 250, 'price' => 20, 'available' => true, 'source_id' => 'v'];
        $registry = new ScraperRegistry([$this->fakeScraper([
            // Name infers nothing useful; scraper supplies the origin.
            ['name' => 'House Blend', 'source_id' => 'A', 'is_blend' => true,
             'origin' => 'Colombia', 'variants' => [$variant]],
            // No origin key → title inference still applies.
            ['name' => 'Ethiopia Yirgacheffe', 'source_id' => 'B', 'is_blend' => false,
             'variants' => [$variant]],
        ])]);

        (new RoasterImporter($registry))->import('https://origin-contract.test', name: 'Origin Contract');

        $this->assertSame('Colombia', Coffee::where('source_id', 'A')->first()->origin,
            'a scraper-provided origin must not be overwritten by inference');
        $this->assertSame('Ethiopia', Coffee::where('source_id', 'B')->first()->origin,
            'inference remains the fallback when no origin is provided');
    }
}
