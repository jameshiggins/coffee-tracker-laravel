<?php

namespace Tests\Feature;

use App\Models\Coffee;
use App\Models\Tasting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * coffees:purge-non-coffee — sweeps active rows the gear/merch filter now
 * rejects (the "Espro French Press got imported as a coffee" class of junk).
 */
class PurgeNonCoffeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_soft_removes_gear_but_keeps_real_coffee(): void
    {
        $bean = Coffee::factory()->create(['name' => 'Ethiopia Yirgacheffe']);
        $gear = Coffee::factory()->create(['name' => 'Espro French Press']);

        $this->artisan('coffees:purge-non-coffee', ['--apply' => true])->assertExitCode(0);

        $this->assertNull($bean->fresh()->removed_at, 'real coffee must be kept');
        $this->assertNotNull($gear->fresh()->removed_at, 'gear must be soft-removed');
    }

    public function test_dry_run_does_not_remove_anything(): void
    {
        $gear = Coffee::factory()->create(['name' => 'Created Co 12oz White Mugs Case of 6']);

        $this->artisan('coffees:purge-non-coffee')->assertExitCode(0);

        $this->assertNull($gear->fresh()->removed_at, 'dry run must not mutate');
    }

    public function test_leaves_non_coffee_rows_that_carry_tastings(): void
    {
        $gear = Coffee::factory()->create(['name' => 'Espro French Press']);
        Tasting::factory()->for($gear)->create();

        $this->artisan('coffees:purge-non-coffee', ['--apply' => true])->assertExitCode(0);

        // Tasting-bearing rows are flagged for manual review, not auto-removed.
        $this->assertNull($gear->fresh()->removed_at);
    }
}
