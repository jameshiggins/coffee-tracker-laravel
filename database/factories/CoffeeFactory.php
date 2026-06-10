<?php

namespace Database\Factories;

use App\Models\Coffee;
use App\Models\Roaster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Coffee>
 */
class CoffeeFactory extends Factory
{
    protected $model = Coffee::class;

    public function definition(): array
    {
        return [
            'roaster_id' => Roaster::factory(),
            'name' => ucwords(fake()->words(2, true)),
            'origin' => fake()->randomElement(['Ethiopia', 'Colombia', 'Kenya', 'Guatemala', 'Brazil', 'Rwanda']),
            'process' => fake()->randomElement(['Washed', 'Natural', 'Honey']),
            'roast_level' => fake()->randomElement(['Light', 'Medium', 'Dark']),
            'is_blend' => false,
            'removed_at' => null,
        ];
    }

    /** Soft-removed from the directory (no longer sold). */
    public function removed(): static
    {
        return $this->state(fn () => ['removed_at' => now()]);
    }
}
