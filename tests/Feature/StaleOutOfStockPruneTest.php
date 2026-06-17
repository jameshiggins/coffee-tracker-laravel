<?php

namespace Tests\Feature;

use App\Models\Roaster;
use App\Services\RoasterImporter;
use App\Services\Scraping\RoasterScraper;
use App\Services\Scraping\ScraperRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * RoasterImporter::pruneStaleOutOfStock — soft-removes coffees that have been
 * fully out of stock past the staleness threshold. Models the 49th Parallel
 * shape: the roaster keeps every sold-out (and re-listed) product in its feed,
 * so the normal missing-row soft-remove never fires and the catalogue balloons.
 */
class StaleOutOfStockPruneTest extends TestCase
{
    use RefreshDatabase;

    /** A scraper that returns a fixed, already-normalized coffee set. */
    private function fakeScraper(array $coffees): RoasterScraper
    {
        return new class($coffees) implements RoasterScraper {
            public function __construct(private array $coffees) {}
            public function canHandle(string $url): bool { return true; }
            public function fetch(string $url): array { return $this->coffees; }
            public function platformKey(): string { return 'shopify'; }
        };
    }

    private function feedVariant(bool $available): array
    {
        return ['grams' => 340, 'price' => 24, 'available' => $available, 'source_id' => 'v'];
    }

    public function test_prunes_long_out_of_stock_but_keeps_recent_oos_and_in_stock(): void
    {
        Http::fake(['*' => Http::response('', 200)]); // about/favicon/shipping are best-effort
        Carbon::setTestNow('2026-06-16 12:00:00');

        $roaster = Roaster::create([
            'slug' => 'stale-test', 'name' => 'Stale Test', 'city' => 'Vancouver',
            'website' => 'https://staletest.test', 'platform' => 'shopify',
            'is_active' => true, 'has_shipping' => true,
        ]);

        // A — fully out of stock, last stock change 61 days ago → STALE → prune.
        $a = $roaster->coffees()->create(['name' => 'Old Discontinued', 'source_id' => 'A', 'origin' => 'Kenya']);
        $a->variants()->create(['bag_weight_grams' => 340, 'price' => 24, 'in_stock' => false, 'in_stock_changed_at' => now()->subDays(61)]);

        // B — fully out of stock, but only for 30 days → might restock → keep.
        $b = $roaster->coffees()->create(['name' => 'Recently Sold Out', 'source_id' => 'B', 'origin' => 'Brazil']);
        $b->variants()->create(['bag_weight_grams' => 340, 'price' => 24, 'in_stock' => false, 'in_stock_changed_at' => now()->subDays(30)]);

        // C — in stock (old timestamp, but currently available) → keep.
        $c = $roaster->coffees()->create(['name' => 'Current Coffee', 'source_id' => 'C', 'origin' => 'Ethiopia']);
        $c->variants()->create(['bag_weight_grams' => 340, 'price' => 24, 'in_stock' => true, 'in_stock_changed_at' => now()->subDays(99)]);

        // The feed STILL lists all three — the roaster never unpublishes them,
        // and stock state is unchanged, so no in_stock_changed_at is re-stamped.
        $registry = new ScraperRegistry([$this->fakeScraper([
            ['name' => 'Old Discontinued',  'source_id' => 'A', 'is_blend' => false, 'variants' => [$this->feedVariant(false)]],
            ['name' => 'Recently Sold Out', 'source_id' => 'B', 'is_blend' => false, 'variants' => [$this->feedVariant(false)]],
            ['name' => 'Current Coffee',    'source_id' => 'C', 'is_blend' => false, 'variants' => [$this->feedVariant(true)]],
        ])]);

        (new RoasterImporter($registry))->import('https://staletest.test', name: 'Stale Test');

        $this->assertNotNull($a->fresh()->removed_at, 'out of stock 61 days → pruned');
        $this->assertNull($b->fresh()->removed_at, 'out of stock only 30 days → kept');
        $this->assertNull($c->fresh()->removed_at, 'in stock → kept');

        Carbon::setTestNow();
    }

    public function test_a_restock_un_removes_a_previously_pruned_coffee(): void
    {
        Http::fake(['*' => Http::response('', 200)]);
        Carbon::setTestNow('2026-06-16 12:00:00');

        $roaster = Roaster::create([
            'slug' => 'restock-test', 'name' => 'Restock Test', 'city' => 'Vancouver',
            'website' => 'https://restock.test', 'platform' => 'shopify',
            'is_active' => true, 'has_shipping' => true,
        ]);
        // Already pruned (stale OOS) in a prior run.
        $coffee = $roaster->coffees()->create(['name' => 'Back In Stock', 'source_id' => 'X', 'origin' => 'Kenya', 'removed_at' => now()->subDays(5)]);
        $coffee->variants()->create(['bag_weight_grams' => 340, 'price' => 24, 'in_stock' => false, 'in_stock_changed_at' => now()->subDays(70)]);

        // Feed now reports it available again → upsert clears removed_at and the
        // stock flip re-stamps in_stock_changed_at, so the prune leaves it.
        $registry = new ScraperRegistry([$this->fakeScraper([
            ['name' => 'Back In Stock', 'source_id' => 'X', 'is_blend' => false, 'variants' => [$this->feedVariant(true)]],
        ])]);
        (new RoasterImporter($registry))->import('https://restock.test', name: 'Restock Test');

        $this->assertNull($coffee->fresh()->removed_at, 'restock revives the soft-removed coffee');

        Carbon::setTestNow();
    }
}
