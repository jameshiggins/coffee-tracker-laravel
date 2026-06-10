<?php

namespace Tests\Feature;

use App\Models\Coffee;
use App\Models\CoffeeVariant;
use App\Models\Roaster;
use App\Models\User;
use App\Services\RoasterImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression coverage for the medium/quick-win fixes from the review:
 * empty-source_id collision, stock-aware best price, login throttling,
 * and future-dated tastings.
 */
class QuickWinsTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_products_without_a_platform_id_do_not_collide(): void
    {
        // Both products omit `id` → scrapers emit source_id ''. Before the fix
        // the second one violated the (roaster_id, source_id) unique index and
        // aborted the whole import.
        Http::fake([
            '*' => Http::response(['products' => [
                [
                    'title' => 'Idless Bean One', 'product_type' => 'Coffee', 'handle' => 'one',
                    'variants' => [['title' => '250g', 'price' => '20.00', 'available' => true]],
                ],
                [
                    'title' => 'Idless Bean Two', 'product_type' => 'Coffee', 'handle' => 'two',
                    'variants' => [['title' => '250g', 'price' => '21.00', 'available' => true]],
                ],
            ]], 200),
        ]);

        $roaster = (new RoasterImporter())->import('https://idless.test', name: 'Idless', city: 'Vancouver');

        $this->assertSame(2, $roaster->coffees()->count());
        $this->assertSame(2, $roaster->coffees()->whereNull('source_id')->count());
    }

    public function test_best_price_per_gram_prefers_in_stock_variants(): void
    {
        $coffee = Coffee::factory()->create();
        // In stock: 340g/$30 = 0.0882/g. Out of stock: 1000g/$40 = 0.0400/g (cheaper, unbuyable).
        CoffeeVariant::factory()->for($coffee)->create(['bag_weight_grams' => 340, 'price' => 30.00, 'in_stock' => true]);
        CoffeeVariant::factory()->for($coffee)->create(['bag_weight_grams' => 1000, 'price' => 40.00, 'in_stock' => false]);

        $this->assertEqualsWithDelta(0.0882, $coffee->fresh('variants')->best_price_per_gram, 0.0001);
    }

    public function test_best_price_per_gram_falls_back_to_all_when_nothing_in_stock(): void
    {
        $coffee = Coffee::factory()->create();
        CoffeeVariant::factory()->for($coffee)->create(['bag_weight_grams' => 340, 'price' => 30.00, 'in_stock' => false]);

        $this->assertEqualsWithDelta(0.0882, $coffee->fresh('variants')->best_price_per_gram, 0.0001);
    }

    public function test_login_is_brute_force_throttled(): void
    {
        $email = 'throttle-victim@example.com';
        User::factory()->create(['email' => $email, 'password' => bcrypt('correct-horse')]);

        // 5 attempts allowed per minute for this email+IP; the 6th is blocked.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong'])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_future_dated_tasting_is_rejected(): void
    {
        $user = User::factory()->create();
        $coffee = Coffee::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/tastings', [
            'coffee_id' => $coffee->id,
            'rating' => 8,
            'tasted_on' => now()->addWeek()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('tasted_on');
    }
}
