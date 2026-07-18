<?php

namespace Tests\Feature\Api;

use App\Models\Coffee;
use App\Models\CoffeeVariant;
use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Completes the GET /api/coffees filter matrix. CoffeeDirectoryApiTest
 * covers origin / in_stock / price; this file covers the remaining filters
 * the frontend's filter UI depends on, plus the validation rejections.
 */
class CoffeeDirectoryFilterTest extends TestCase
{
    use RefreshDatabase;

    private function coffee(Roaster $roaster, array $attrs): Coffee
    {
        $coffee = Coffee::factory()->for($roaster)->create($attrs);
        CoffeeVariant::factory()->for($coffee)->create([
            'bag_weight_grams' => 250, 'price' => 20.00, 'in_stock' => true,
        ]);

        return $coffee;
    }

    public function test_filters_by_process(): void
    {
        $roaster = Roaster::factory()->create();
        $this->coffee($roaster, ['name' => 'A', 'process' => 'Washed']);
        $this->coffee($roaster, ['name' => 'B', 'process' => 'Natural']);

        $this->getJson('/api/coffees?process=Natural')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'B');
    }

    public function test_filters_by_roast_level(): void
    {
        $roaster = Roaster::factory()->create();
        $this->coffee($roaster, ['name' => 'A', 'roast_level' => 'Light']);
        $this->coffee($roaster, ['name' => 'B', 'roast_level' => 'Dark']);

        $this->getJson('/api/coffees?roast=Dark')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'B');
    }

    public function test_filters_by_roaster_slug(): void
    {
        $mine = Roaster::factory()->create(['slug' => 'target-roaster']);
        $other = Roaster::factory()->create();
        $this->coffee($mine, ['name' => 'Mine']);
        $this->coffee($other, ['name' => 'Other']);

        $this->getJson('/api/coffees?roaster=target-roaster')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mine');
    }

    public function test_filters_by_is_blend_in_both_directions(): void
    {
        $roaster = Roaster::factory()->create();
        $this->coffee($roaster, ['name' => 'Blend', 'is_blend' => true]);
        $this->coffee($roaster, ['name' => 'Single', 'is_blend' => false]);

        $this->getJson('/api/coffees?is_blend=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Blend');

        // is_blend=0 must mean "singles only", not "filter off".
        $this->getJson('/api/coffees?is_blend=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Single');
    }

    public function test_default_sort_is_name_and_newest_sorts_by_id_desc(): void
    {
        $roaster = Roaster::factory()->create();
        $this->coffee($roaster, ['name' => 'Zebra']);
        $this->coffee($roaster, ['name' => 'Aardvark']);

        $this->getJson('/api/coffees')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Aardvark');

        // Zebra was created first, so "newest" puts Aardvark on top too —
        // but by id, not name. Add a third row to tell the orders apart.
        $this->coffee($roaster, ['name' => 'Middle']);

        $this->getJson('/api/coffees?sort=newest')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Middle')
            ->assertJsonPath('data.1.name', 'Aardvark')
            ->assertJsonPath('data.2.name', 'Zebra');
    }

    public function test_rejects_invalid_filter_values(): void
    {
        $this->getJson('/api/coffees?sort=bogus')->assertStatus(422);
        $this->getJson('/api/coffees?per_page=0')->assertStatus(422);
        $this->getJson('/api/coffees?per_page=1000')->assertStatus(422);
        $this->getJson('/api/coffees?is_blend=maybe')->assertStatus(422);
        $this->getJson('/api/coffees?min_cents_per_gram=-1')->assertStatus(422);
    }
}
