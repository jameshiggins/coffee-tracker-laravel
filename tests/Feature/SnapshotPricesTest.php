<?php

namespace Tests\Feature;

use App\Models\PriceHistory;
use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnapshotPricesTest extends TestCase
{
    use RefreshDatabase;

    private function seedTwoVariants(): void
    {
        $roaster = Roaster::create(['name' => 'R', 'slug' => 'r', 'city' => 'Vancouver']);
        $coffee = $roaster->coffees()->create(['name' => 'Yirg', 'origin' => 'Ethiopia']);
        $coffee->variants()->create(['bag_weight_grams' => 250, 'price' => 24.00, 'in_stock' => true]);
        $coffee->variants()->create(['bag_weight_grams' => 340, 'price' => 30.00, 'in_stock' => false]);
    }

    public function test_snapshot_records_one_price_history_row_per_variant(): void
    {
        $this->seedTwoVariants();
        $this->assertSame(0, PriceHistory::count());

        $this->artisan('prices:snapshot')->assertExitCode(0);

        $this->assertSame(2, PriceHistory::count());
    }

    public function test_snapshot_preserves_in_stock_state_per_variant(): void
    {
        $this->seedTwoVariants();
        $this->artisan('prices:snapshot')->assertExitCode(0);

        $rows = PriceHistory::orderBy('coffee_variant_id')->get();
        $this->assertTrue($rows[0]->in_stock);
        $this->assertFalse($rows[1]->in_stock);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->seedTwoVariants();
        $this->artisan('prices:snapshot', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSame(0, PriceHistory::count());
    }

    public function test_snapshot_is_idempotent_within_the_same_day(): void
    {
        // Running the daily snapshot twice must NOT create two rows per variant
        // for the same calendar day — the chart would show duplicate points and
        // the storage would grow unbounded if a cron retries on failure.
        $this->seedTwoVariants();

        $this->artisan('prices:snapshot')->assertExitCode(0);
        $this->artisan('prices:snapshot')->assertExitCode(0);

        $this->assertSame(2, PriceHistory::count(),
            'two snapshots in one day should still leave one row per variant');
    }

    public function test_snapshot_replaces_same_day_row_when_price_changed(): void
    {
        // If you snapshot, the price changes mid-day, then snapshot again, the
        // record for today should reflect the latest price (not the stale earlier one).
        $this->seedTwoVariants();
        $this->artisan('prices:snapshot');

        $variant = \App\Models\CoffeeVariant::where('bag_weight_grams', 250)->first();
        $variant->update(['price' => 30.00]);
        $this->artisan('prices:snapshot');

        $row = PriceHistory::where('coffee_variant_id', $variant->id)->first();
        $this->assertEquals(30.00, $row->price);
        $this->assertSame(2, PriceHistory::count(), 'still one row per variant for today');
    }

    public function test_snapshots_on_different_days_create_separate_rows(): void
    {
        $this->seedTwoVariants();
        $variant = \App\Models\CoffeeVariant::where('bag_weight_grams', 250)->first();

        // Yesterday's snapshot, simulated by inserting directly with a past timestamp.
        PriceHistory::create([
            'coffee_variant_id' => $variant->id, 'price' => 22.00, 'in_stock' => true,
            'recorded_at' => now()->subDay(),
        ]);

        $this->artisan('prices:snapshot');

        $rows = PriceHistory::where('coffee_variant_id', $variant->id)
            ->orderBy('recorded_at')->get();
        $this->assertCount(2, $rows, 'yesterday + today should both exist');
    }

    public function test_snapshot_records_current_variant_price_not_a_stale_value(): void
    {
        $this->seedTwoVariants();
        // Update one variant price between snapshots — the new snapshot must reflect the change.
        $variant = \App\Models\CoffeeVariant::where('bag_weight_grams', 250)->first();
        $variant->update(['price' => 26.50]);

        $this->artisan('prices:snapshot')->assertExitCode(0);

        $latest = PriceHistory::where('coffee_variant_id', $variant->id)->latest('id')->first();
        $this->assertEquals(26.50, $latest->price);
    }
}
