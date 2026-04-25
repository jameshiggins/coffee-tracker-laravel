<?php

namespace Tests\Feature;

use App\Models\Coffee;
use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoffeeBestPriceTest extends TestCase
{
    use RefreshDatabase;

    private function makeCoffee(array $variants): Coffee
    {
        $roaster = Roaster::create([
            'name' => 'Test Roaster',
            'slug' => 'test-roaster',
            'city' => 'Vancouver',
        ]);
        $coffee = $roaster->coffees()->create([
            'name' => 'Test Bean',
            'origin' => 'Ethiopia',
        ]);
        foreach ($variants as $v) {
            $coffee->variants()->create([
                'bag_weight_grams' => $v[0],
                'price' => $v[1],
            ]);
        }
        return $coffee->fresh('variants');
    }

    public function test_best_price_per_gram_picks_the_cheapest_per_gram_not_the_cheapest_total(): void
    {
        // 250g/$24 = $0.0960/g, 1000g/$58 = $0.0580/g
        // The 1kg costs more but is cheaper per gram — should win.
        $coffee = $this->makeCoffee([[250, 24.00], [1000, 58.00]]);
        $this->assertEqualsWithDelta(0.058, $coffee->best_price_per_gram, 0.0001);
    }

    public function test_best_price_per_gram_with_single_variant(): void
    {
        $coffee = $this->makeCoffee([[340, 22.00]]);
        $this->assertEqualsWithDelta(0.0647, $coffee->best_price_per_gram, 0.0001);
    }

    public function test_best_price_per_gram_returns_null_when_no_variants(): void
    {
        $roaster = Roaster::create(['name' => 'Empty', 'slug' => 'empty', 'city' => 'Vancouver']);
        $coffee = $roaster->coffees()->create(['name' => 'Empty Bean', 'origin' => 'Brazil']);
        $coffee->load('variants');
        $this->assertNull($coffee->best_price_per_gram);
    }

    public function test_best_price_per_gram_ignores_zero_weight_variants(): void
    {
        // A bad data row with 0 grams must not be picked (would be infinity)
        // and must not throw — it should be filtered.
        $coffee = $this->makeCoffee([[0, 5.00], [340, 22.00]]);
        $this->assertEqualsWithDelta(0.0647, $coffee->best_price_per_gram, 0.0001);
    }
}
