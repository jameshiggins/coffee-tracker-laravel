<?php

namespace Database\Factories;

use App\Models\Roaster;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Roaster>
 */
class RoasterFactory extends Factory
{
    protected $model = Roaster::class;

    public function definition(): array
    {
        $name = fake()->unique()->company() . ' Coffee';

        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . fake()->unique()->numberBetween(1, 999999),
            'city' => fake()->city(),
            'region' => fake()->randomElement(['BC', 'ON', 'QC', 'AB', 'NS']),
            'country_code' => 'CA',
            'website' => 'https://' . fake()->unique()->domainName(),
            'is_active' => true,
            'has_shipping' => true,
            'has_subscription' => false,
        ];
    }

    /** Hidden from the public directory. */
    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
