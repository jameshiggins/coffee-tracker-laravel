<?php

namespace Database\Factories;

use App\Models\Coffee;
use App\Models\CoffeeVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CoffeeVariant>
 */
class CoffeeVariantFactory extends Factory
{
    protected $model = CoffeeVariant::class;

    public function definition(): array
    {
        return [
            'coffee_id' => Coffee::factory(),
            'bag_weight_grams' => fake()->randomElement([250, 340, 454, 1000]),
            'price' => fake()->randomFloat(2, 15, 40),
            'currency_code' => 'CAD',
            'in_stock' => true,
        ];
    }

    public function outOfStock(): static
    {
        return $this->state(fn () => ['in_stock' => false]);
    }
}
