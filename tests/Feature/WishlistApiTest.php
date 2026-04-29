<?php

namespace Tests\Feature;

use App\Models\Coffee;
use App\Models\Roaster;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WishlistApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeCoffee(): Coffee
    {
        $suffix = substr(bin2hex(random_bytes(3)), 0, 6);
        $r = Roaster::create(['name' => "R-{$suffix}", 'slug' => "r-{$suffix}", 'city' => 'V']);
        return $r->coffees()->create(['name' => 'Yirg', 'origin' => 'Ethiopia']);
    }

    private function makeUser(string $email = 'a@example.com'): User
    {
        $suffix = substr(bin2hex(random_bytes(3)), 0, 6);
        return User::create([
            'name' => 'A', 'email' => $email,
            'display_name' => 'taster_' . $suffix,
            'password' => bcrypt('x'),
        ]);
    }

    public function test_unauthenticated_post_is_rejected(): void
    {
        $coffee = $this->makeCoffee();
        $this->postJson('/api/wishlist', ['coffee_id' => $coffee->id])->assertUnauthorized();
    }

    public function test_authenticated_user_can_add_to_wishlist(): void
    {
        $user = $this->makeUser();
        $coffee = $this->makeCoffee();
        Sanctum::actingAs($user);

        $this->postJson('/api/wishlist', ['coffee_id' => $coffee->id])
            ->assertCreated()
            ->assertJsonPath('wishlist.coffee_id', $coffee->id);

        $this->assertDatabaseHas('wishlists', ['user_id' => $user->id, 'coffee_id' => $coffee->id]);
    }

    public function test_adding_same_coffee_twice_is_idempotent(): void
    {
        $user = $this->makeUser();
        $coffee = $this->makeCoffee();
        Sanctum::actingAs($user);

        $this->postJson('/api/wishlist', ['coffee_id' => $coffee->id])->assertCreated();
        $this->postJson('/api/wishlist', ['coffee_id' => $coffee->id])->assertCreated();

        $this->assertSame(1, Wishlist::where('user_id', $user->id)->count(), 'must not duplicate');
    }

    public function test_index_returns_only_my_own_wishlist(): void
    {
        $alice = $this->makeUser('alice@example.com');
        $bob = $this->makeUser('bob@example.com');
        $coffee1 = $this->makeCoffee();
        $coffee2 = $this->makeCoffee();

        Wishlist::create(['user_id' => $alice->id, 'coffee_id' => $coffee1->id]);
        Wishlist::create(['user_id' => $bob->id,   'coffee_id' => $coffee2->id]);

        Sanctum::actingAs($alice);
        $items = $this->getJson('/api/wishlist')->json('items');
        $this->assertCount(1, $items);
        $this->assertSame($coffee1->id, $items[0]['coffee']['id']);
    }

    public function test_index_returns_coffee_with_roaster_and_is_removed_flag(): void
    {
        $user = $this->makeUser();
        $coffee = $this->makeCoffee();
        Wishlist::create(['user_id' => $user->id, 'coffee_id' => $coffee->id]);

        Sanctum::actingAs($user);
        $payload = $this->getJson('/api/wishlist')->json('items.0.coffee');
        $this->assertSame('Yirg', $payload['name']);
        $this->assertFalse($payload['is_removed']);
        $this->assertStringStartsWith('R-', $payload['roaster']['name']);
    }

    public function test_destroy_removes_only_my_entry(): void
    {
        $alice = $this->makeUser('alice@example.com');
        $bob = $this->makeUser('bob@example.com');
        $coffee = $this->makeCoffee();

        Wishlist::create(['user_id' => $alice->id, 'coffee_id' => $coffee->id]);
        Wishlist::create(['user_id' => $bob->id,   'coffee_id' => $coffee->id]);

        Sanctum::actingAs($alice);
        $this->deleteJson("/api/wishlist/{$coffee->id}")->assertNoContent();

        $this->assertDatabaseMissing('wishlists', ['user_id' => $alice->id, 'coffee_id' => $coffee->id]);
        $this->assertDatabaseHas('wishlists',     ['user_id' => $bob->id,   'coffee_id' => $coffee->id]);
    }

    public function test_destroy_unauth_rejected(): void
    {
        $coffee = $this->makeCoffee();
        $this->deleteJson("/api/wishlist/{$coffee->id}")->assertUnauthorized();
    }

    public function test_destroy_when_not_wishlisted_is_no_op(): void
    {
        $user = $this->makeUser();
        $coffee = $this->makeCoffee();

        Sanctum::actingAs($user);
        $this->deleteJson("/api/wishlist/{$coffee->id}")->assertNoContent();
    }
}
