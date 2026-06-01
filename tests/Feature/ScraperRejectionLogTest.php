<?php

namespace Tests\Feature;

use App\Models\ScraperRejectionLog;
use App\Services\RoasterImporter;
use App\Services\Scraping\RoasterScraper;
use App\Services\Scraping\ScraperRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Trust#9: the importer's variant sanity gate (Trust#8 — non-positive price,
 * cents-per-gram outside 2.5–250) must leave an observable breadcrumb instead
 * of silently dropping the variant.
 *
 * These tests feed raw normalized rows straight through the importer via a stub
 * scraper, bypassing the per-platform scrapers' own pre-filters (Shopify, for
 * instance, drops $0 variants itself at normalize time) so the importer's gate
 * is exercised directly and deterministically.
 */
class ScraperRejectionLogTest extends TestCase
{
    use RefreshDatabase;

    /** A scraper that returns canned normalized rows — no network, no platform logic. */
    private function importerReturning(array $rows): RoasterImporter
    {
        $stub = new class($rows) implements RoasterScraper {
            public function __construct(private array $rows) {}
            public function canHandle(string $url): bool { return true; }
            public function fetch(string $url): array { return $this->rows; }
            public function platformKey(): string { return 'stub'; }
        };

        return new RoasterImporter(new ScraperRegistry([$stub]));
    }

    private function coffeeRow(array $variants, string $name = 'Test Coffee', string $sourceId = 'p1'): array
    {
        return [
            'name' => $name,
            'source_id' => $sourceId,
            'description' => '',
            'image_url' => null,
            'product_url' => 'https://roasterexample.com/products/test',
            'is_blend' => false,
            'variants' => $variants,
        ];
    }

    public function test_zero_price_and_out_of_band_variants_are_logged_and_dropped(): void
    {
        Http::fake(); // neutralize the best-effort about/favicon/shipping scrapers

        $rows = [$this->coffeeRow([
            ['grams' => 250, 'price' => 24.00, 'available' => true],  // 9.6¢/g — kept
            ['grams' => 500, 'price' => 0.0, 'available' => true],    // $0 — rejected
            ['grams' => 10, 'price' => 30.00, 'available' => true],   // 300¢/g — rejected
        ])];

        $roaster = $this->importerReturning($rows)
            ->import('https://roasterexample.com', name: 'Roaster Example', city: 'Vancouver');

        $coffee = $roaster->coffees()->with('variants')->first();
        $this->assertNotNull($coffee);
        // Only the in-band 250g/$24 variant is persisted.
        $this->assertSame(1, $coffee->variants()->count());
        $this->assertEquals(250, $coffee->variants()->first()->bag_weight_grams);

        $logs = ScraperRejectionLog::where('roaster_id', $roaster->id)->get();
        $this->assertCount(2, $logs);

        $byReason = $logs->keyBy('reason');
        $this->assertTrue($byReason->has(ScraperRejectionLog::REASON_PRICE_NON_POSITIVE));
        $this->assertTrue($byReason->has(ScraperRejectionLog::REASON_CPG_OUT_OF_BAND));

        // The out-of-band log carries the offending numbers and a readable snapshot.
        $oob = $byReason->get(ScraperRejectionLog::REASON_CPG_OUT_OF_BAND);
        $this->assertSame($coffee->id, $oob->coffee_id);
        $this->assertSame($coffee->name, $oob->coffee_name);
        $this->assertSame(10, $oob->context['grams']);
        $this->assertEqualsWithDelta(300.0, $oob->context['cpg'], 0.01);

        $zero = $byReason->get(ScraperRejectionLog::REASON_PRICE_NON_POSITIVE);
        $this->assertSame(500, $zero->context['grams']);
    }

    public function test_rejection_logs_are_replaced_not_accumulated_on_reimport(): void
    {
        Http::fake();

        $rows = [$this->coffeeRow([
            ['grams' => 250, 'price' => 24.00, 'available' => true],
            ['grams' => 10, 'price' => 30.00, 'available' => true],
        ])];

        $importer = $this->importerReturning($rows);
        $importer->import('https://roasterexample.com', name: 'Roaster Example', city: 'Vancouver');
        $importer->import('https://roasterexample.com', name: 'Roaster Example', city: 'Vancouver');

        // Snapshot semantics: the second run clears the first's rows and re-logs,
        // so there's exactly ONE rejection rather than two accumulated copies.
        $this->assertSame(1, ScraperRejectionLog::count());
    }

    public function test_clean_reimport_clears_prior_rejection_logs(): void
    {
        Http::fake();

        $dirty = [$this->coffeeRow([
            ['grams' => 250, 'price' => 24.00, 'available' => true],
            ['grams' => 10, 'price' => 30.00, 'available' => true],
        ])];
        $clean = [$this->coffeeRow([
            ['grams' => 250, 'price' => 24.00, 'available' => true],
        ])];

        $this->importerReturning($dirty)
            ->import('https://roasterexample.com', name: 'Roaster Example', city: 'Vancouver');
        $this->assertSame(1, ScraperRejectionLog::count());

        // A subsequent clean import must wipe the prior run's rejection.
        $this->importerReturning($clean)
            ->import('https://roasterexample.com', name: 'Roaster Example', city: 'Vancouver');
        $this->assertSame(0, ScraperRejectionLog::count());
    }
}
