<?php

namespace Tests\Feature\Api;

use App\Models\Coffee;
use App\Models\CoffeeVariant;
use App\Models\Roaster;
use App\Services\RoasterImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Rec 4: the bean-centric GET /api/coffees listing (pagination + filters +
 * sort), plus the H5 "deactivated roaster must 404" moderation fix.
 */
class CoffeeDirectoryApiTest extends TestCase
{
    use RefreshDatabase;

    private function coffeeWithPrice(Roaster $roaster, array $attrs, int $grams, float $price, bool $inStock = true): Coffee
    {
        $coffee = Coffee::factory()->for($roaster)->create($attrs);
        CoffeeVariant::factory()->for($coffee)->create([
            'bag_weight_grams' => $grams, 'price' => $price, 'in_stock' => $inStock,
        ]);
        $coffee->load('variants');
        $coffee->refreshBestCentsPerGram();

        return $coffee;
    }

    public function test_index_paginates_and_returns_meta(): void
    {
        $roaster = Roaster::factory()->create();
        for ($i = 0; $i < 30; $i++) {
            $this->coffeeWithPrice($roaster, ['name' => "Bean {$i}"], 250, 20.00);
        }

        $this->getJson('/api/coffees?per_page=10')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 30)
            ->assertJsonPath('meta.per_page', 10);
    }

    public function test_index_filters_by_origin(): void
    {
        $roaster = Roaster::factory()->create();
        $this->coffeeWithPrice($roaster, ['name' => 'Yirg', 'origin' => 'Ethiopia'], 250, 20.00);
        $this->coffeeWithPrice($roaster, ['name' => 'Narino', 'origin' => 'Colombia'], 250, 20.00);

        $this->getJson('/api/coffees?origin=Ethiopia')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.origin', 'Ethiopia');
    }

    public function test_index_filters_by_in_stock(): void
    {
        $roaster = Roaster::factory()->create();
        $this->coffeeWithPrice($roaster, ['name' => 'Available'], 250, 20.00, inStock: true);
        $this->coffeeWithPrice($roaster, ['name' => 'Sold Out'], 250, 20.00, inStock: false);

        $this->getJson('/api/coffees?in_stock=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Available');
    }

    public function test_index_filters_and_sorts_by_price_per_gram(): void
    {
        $roaster = Roaster::factory()->create();
        $this->coffeeWithPrice($roaster, ['name' => 'Cheap'], 1000, 50.00);  // 5.0 c/g
        $this->coffeeWithPrice($roaster, ['name' => 'Pricey'], 250, 50.00);  // 20.0 c/g

        // Sort ascending → cheapest per gram first.
        $this->getJson('/api/coffees?sort=price_asc')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Cheap')
            ->assertJsonPath('data.1.name', 'Pricey');

        // Filter out the cheap one.
        $this->getJson('/api/coffees?min_cents_per_gram=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Pricey');
    }

    public function test_index_excludes_coffees_of_inactive_roasters(): void
    {
        $active = Roaster::factory()->create();
        $inactive = Roaster::factory()->inactive()->create();
        $this->coffeeWithPrice($active, ['name' => 'Shown'], 250, 20.00);
        $this->coffeeWithPrice($inactive, ['name' => 'Hidden'], 250, 20.00);

        $this->getJson('/api/coffees')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Shown');
    }

    public function test_coffee_show_404s_when_roaster_is_inactive(): void
    {
        $roaster = Roaster::factory()->inactive()->create();
        $coffee = Coffee::factory()->for($roaster)->create();

        $this->getJson("/api/coffees/{$coffee->id}")->assertStatus(404);
    }

    public function test_roaster_show_404s_when_inactive(): void
    {
        $roaster = Roaster::factory()->inactive()->create();

        $this->getJson("/api/roasters/{$roaster->slug}")->assertStatus(404);
    }

    public function test_import_populates_best_cents_per_gram(): void
    {
        Http::fake([
            '*' => Http::response(['products' => [[
                'id' => 1, 'title' => 'Ethiopia Yirgacheffe', 'product_type' => 'Coffee',
                'handle' => 'yirg', 'tags' => ['Single Origin'],
                'variants' => [['id' => 11, 'title' => '250g', 'price' => '25.00', 'available' => true]],
            ]]], 200),
        ]);

        $roaster = (new RoasterImporter())->import('https://cpgtest.test', name: 'CPG', city: 'Vancouver');
        $coffee = $roaster->coffees()->first();

        // 25.00 / 250g * 100 = 10 cents/gram.
        $this->assertSame(10, $coffee->best_cents_per_gram);
    }
}
