<?php

namespace Database\Factories;

use App\Models\Coffee;
use App\Models\Tasting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Tasting>
 */
class TastingFactory extends Factory
{
    protected $model = Tasting::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'coffee_id' => Coffee::factory(),
            'rating' => fake()->numberBetween(1, 10),
            'notes' => fake()->optional()->sentence(),
            'tasted_on' => fake()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'is_public' => true,
        ];
    }

    public function private(): static
    {
        return $this->state(fn () => ['is_public' => false]);
    }
}
