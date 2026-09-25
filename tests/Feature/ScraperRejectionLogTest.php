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

    public function test_bulk_bags_get_a_lower_floor_than_retail_bags(): void
    {
        Http::fake();

        $rows = [$this->coffeeRow([
            ['grams' => 3000, 'price' => 69.00, 'available' => true, 'source_size_label' => '3 kg'], // 2.3¢/g — real office bag, kept
            ['grams' => 340, 'price' => 7.00, 'available' => true],                                   // 2.06¢/g — retail floor is 2.5, dropped
        ])];

        $roaster = $this->importerReturning($rows)
            ->import('https://roasterexample.com', name: 'Roaster Example', city: 'Vancouver');

        $kept = $roaster->coffees()->first()->variants()->pluck('bag_weight_grams')->all();
        $this->assertSame([3000], $kept);

        $log = ScraperRejectionLog::firstOrFail();
        $this->assertSame(340, $log->context['grams']);
        $this->assertSame(2.5, $log->context['floor']);
    }

    public function test_variant_priced_like_a_far_smaller_sibling_is_rejected_as_inconsistent(): void
    {
        Http::fake();

        // "200 g" mis-read as 2 kg: same price as the real 200 g bag for ten
        // times the coffee. 1.75¢/g clears the bulk floor, so only the sibling
        // check can catch it.
        $rows = [$this->coffeeRow([
            ['grams' => 200, 'price' => 35.00, 'available' => true],
            ['grams' => 2000, 'price' => 35.00, 'available' => true, 'source_size_label' => '2 kg'],
        ], name: 'Colombia El Obraje Geisha')];

        $roaster = $this->importerReturning($rows)
            ->import('https://roasterexample.com', name: 'Roaster Example', city: 'Vancouver');

        $this->assertSame([200], $roaster->coffees()->first()->variants()->pluck('bag_weight_grams')->all());

        $log = ScraperRejectionLog::firstOrFail();
        $this->assertSame(ScraperRejectionLog::REASON_CPG_INCONSISTENT, $log->reason);
        $this->assertSame(ScraperRejectionLog::SUSPECT_UNIT_ERROR, $log->context['suspected']);
        $this->assertSame(200, $log->context['sibling_grams']);
        $this->assertEquals(35.0, $log->context['sibling_price']);
    }

    public function test_a_genuine_bulk_discount_is_not_flagged_as_inconsistent(): void
    {
        Http::fake();

        // 5 lb at 2.2¢/g next to 340 g at 5.3¢/g: 6.7× the grams for 2.7× the
        // money. Normal bulk pricing — both stay.
        $rows = [$this->coffeeRow([
            ['grams' => 340, 'price' => 18.00, 'available' => true],
            ['grams' => 2268, 'price' => 49.00, 'available' => true, 'source_size_label' => '5 lb'],
        ], name: 'Fife Blend')];

        $roaster = $this->importerReturning($rows)
            ->import('https://roasterexample.com', name: 'Roaster Example', city: 'Vancouver');

        $this->assertEqualsCanonicalizing([340, 2268], $roaster->coffees()->first()->variants()->pluck('bag_weight_grams')->all());
        $this->assertSame(0, ScraperRejectionLog::count());
    }

    public function test_each_rejection_carries_a_suspected_cause(): void
    {
        Http::fake();

        $rows = [
            // A café drink that reached the importer (the stub bypasses the
            // scraper-level classifier): 12 oz cup read as 340 g.
            $this->coffeeRow([['grams' => 340, 'price' => 7.00, 'available' => true]], name: 'Blueberry Pancake Latte', sourceId: 'drink'),
            // A tiny expensive bag: sample / portion pack.
            $this->coffeeRow([['grams' => 50, 'price' => 150.00, 'available' => true]], name: 'Geisha Taster', sourceId: 'sample'),
            // Bulk bag under even the bulk floor: plausible bulk pricing, flagged for a look.
            $this->coffeeRow([['grams' => 3000, 'price' => 40.00, 'available' => true]], name: 'Office Blend', sourceId: 'bulk'),
            // Absurdly cheap retail bag: bag-size mis-parse.
            $this->coffeeRow([['grams' => 907, 'price' => 3.00, 'available' => true]], name: 'House Blend', sourceId: 'unit'),
        ];

        $this->importerReturning($rows)
            ->import('https://roasterexample.com', name: 'Roaster Example', city: 'Vancouver');

        $byName = ScraperRejectionLog::all()->keyBy('coffee_name');
        $this->assertSame(ScraperRejectionLog::SUSPECT_NON_COFFEE, $byName['Blueberry Pancake Latte']->context['suspected']);
        $this->assertSame(ScraperRejectionLog::SUSPECT_SAMPLE_OR_PORTION, $byName['Geisha Taster']->context['suspected']);
        $this->assertSame(ScraperRejectionLog::SUSPECT_BULK_PRICING, $byName['Office Blend']->context['suspected']);
        $this->assertSame(ScraperRejectionLog::SUSPECT_UNIT_ERROR, $byName['House Blend']->context['suspected']);
    }

    public function test_first_seen_and_reviewed_survive_a_reimport(): void
    {
        Http::fake();

        $rows = [$this->coffeeRow([
            ['grams' => 250, 'price' => 24.00, 'available' => true],
            ['grams' => 10, 'price' => 30.00, 'available' => true],
        ])];
        $importer = $this->importerReturning($rows);

        $this->travelTo(now()->subDays(10));
        $importer->import('https://roasterexample.com', name: 'Roaster Example', city: 'Vancouver');
        $first = ScraperRejectionLog::firstOrFail();
        $firstSeen = $first->first_seen_at->toDateTimeString();
        $this->assertSame(now()->toDateTimeString(), $firstSeen, 'first sighting stamps first_seen_at');

        // An operator looks at it and says "fine".
        $first->update(['reviewed_at' => now()]);
        $this->travelBack();

        // Tonight's import replaces the snapshot — the row is new, the history is not.
        $importer->import('https://roasterexample.com', name: 'Roaster Example', city: 'Vancouver');
        $again = ScraperRejectionLog::firstOrFail();
        $this->assertNotSame($first->id, $again->id, 'snapshot rows are replaced');
        $this->assertSame($firstSeen, $again->first_seen_at->toDateTimeString(), 'first_seen_at carried over');
        $this->assertNotNull($again->reviewed_at, 'reviewed_at carried over');

        // A drop that appears for the first time today is stamped today.
        $this->importerReturning([$this->coffeeRow([
            ['grams' => 250, 'price' => 24.00, 'available' => true],
            ['grams' => 10, 'price' => 30.00, 'available' => true],
            ['grams' => 5, 'price' => 30.00, 'available' => true], // new this run
        ])])->import('https://roasterexample.com', name: 'Roaster Example', city: 'Vancouver');

        $fresh = ScraperRejectionLog::all()->first(fn ($r) => ($r->context['grams'] ?? null) === 5);
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->first_seen_at->isToday());
        $this->assertNull($fresh->reviewed_at);
    }
}
