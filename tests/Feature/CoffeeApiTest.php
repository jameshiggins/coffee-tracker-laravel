<?php

namespace Tests\Feature;

use App\Models\Coffee;
use App\Models\Roaster;
use App\Models\Tasting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoffeeApiTest extends TestCase
{
    use RefreshDatabase;

    private function seedCoffee(array $coffeeOverrides = []): Coffee
    {
        $roaster = Roaster::create([
            'name' => 'Sey Coffee',
            'slug' => 'sey-coffee',
            'city' => 'Brooklyn',
            'region' => 'New York',
            'country_code' => 'US',
            'website' => 'https://www.seycoffee.com',
        ]);
        $coffee = $roaster->coffees()->create(array_merge([
            'name' => 'Yirgacheffe Natural',
            'origin' => 'Ethiopia',
            'process' => 'natural',
            'roast_level' => 'light',
            'is_blend' => false,
            'description' => 'Floral, bright, juicy.',
        ], $coffeeOverrides));
        $coffee->variants()->create([
            'bag_weight_grams' => 250,
            'price' => 24.50,
            'in_stock' => true,
        ]);
        return $coffee;
    }

    public function test_show_returns_coffee_with_roaster_variants_and_zero_rating(): void
    {
        $coffee = $this->seedCoffee();

        $response = $this->getJson("/api/coffees/{$coffee->id}");

        $response->assertOk()
            ->assertJsonPath('coffee.name', 'Yirgacheffe Natural')
            ->assertJsonPath('coffee.roaster.name', 'Sey Coffee')
            ->assertJsonPath('coffee.roaster.country_code', 'US')
            ->assertJsonPath('coffee.rating.count', 0)
            ->assertJsonPath('coffee.rating.average', null)
            ->assertJsonCount(1, 'coffee.variants');
    }

    public function test_show_returns_aggregated_rating_when_public_tastings_exist(): void
    {
        $coffee = $this->seedCoffee();
        $u1 = User::create(['name' => 'A', 'email' => 'a@a.com', 'password' => bcrypt('x')]);
        $u2 = User::create(['name' => 'B', 'email' => 'b@a.com', 'password' => bcrypt('x')]);

        Tasting::create(['user_id' => $u1->id, 'coffee_id' => $coffee->id, 'rating' => 8, 'tasted_on' => '2026-04-01', 'is_public' => true]);
        Tasting::create(['user_id' => $u2->id, 'coffee_id' => $coffee->id, 'rating' => 10, 'tasted_on' => '2026-04-02', 'is_public' => true]);
        // private one — must NOT contribute to the aggregate
        Tasting::create(['user_id' => $u1->id, 'coffee_id' => $coffee->id, 'rating' => 2, 'tasted_on' => '2026-04-03', 'is_public' => false]);

        $response = $this->getJson("/api/coffees/{$coffee->id}")->json('coffee.rating');

        $this->assertSame(2, $response['count'], 'private tasting must be excluded from public aggregate');
        $this->assertEquals(9, $response['average'], '8 + 10 / 2 = 9 on the 1-10 internal scale');
        $this->assertSame(4.5, $response['average_stars'], '9 / 2 = 4.5 stars');
    }

    public function test_show_excludes_unrated_public_tastings_from_average_but_counts_them(): void
    {
        $coffee = $this->seedCoffee();
        $u = User::create(['name' => 'A', 'email' => 'a@a.com', 'password' => bcrypt('x')]);

        Tasting::create(['user_id' => $u->id, 'coffee_id' => $coffee->id, 'rating' => 8, 'tasted_on' => '2026-04-01', 'is_public' => true]);
        Tasting::create(['user_id' => $u->id, 'coffee_id' => $coffee->id, 'rating' => null, 'tasted_on' => '2026-04-02', 'is_public' => true]);

        $rating = $this->getJson("/api/coffees/{$coffee->id}")->json('coffee.rating');
        // The average is computed over rated-only; count is also rated-only
        // so users see "8 / 1 rating" not "8 / 2 (one without rating)".
        $this->assertSame(1, $rating['count']);
        $this->assertEquals(8, $rating['average']);
    }

    public function test_show_returns_404_for_unknown_id(): void
    {
        $this->getJson('/api/coffees/9999')->assertNotFound();
    }

    public function test_show_returns_soft_removed_coffees_with_is_removed_flag(): void
    {
        $coffee = $this->seedCoffee(['removed_at' => now()]);
        $response = $this->getJson("/api/coffees/{$coffee->id}");
        $response->assertOk()->assertJsonPath('coffee.is_removed', true);
    }
}
