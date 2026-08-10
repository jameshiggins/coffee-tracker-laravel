<?php

namespace Tests\Feature;

use App\Models\Roaster;
use App\Models\RoasterFavorite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Per-user pinned/favorite roasters. Mirrors the wishlist contract:
 * private to the owner, idempotent add, ownership-scoped delete.
 * The React directory pins favorites to the top of the roaster list.
 */
class FavoriteRoasterApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeRoaster(array $overrides = []): Roaster
    {
        $suffix = substr(bin2hex(random_bytes(3)), 0, 6);
        return Roaster::create(array_merge([
            'name' => "R-{$suffix}",
            'slug' => "r-{$suffix}",
            'city' => 'Vancouver',
            'region' => 'Vancouver',
            'website' => 'https://example.com',
        ], $overrides));
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

    public function test_all_endpoints_reject_unauthenticated(): void
    {
        $roaster = $this->makeRoaster();
        $this->getJson('/api/me/favorite-roasters')->assertUnauthorized();
        $this->postJson('/api/me/favorite-roasters', ['roaster_id' => $roaster->id])->assertUnauthorized();
        $this->deleteJson("/api/me/favorite-roasters/{$roaster->slug}")->assertUnauthorized();
    }

    public function test_index_is_empty_for_a_new_user(): void
    {
        Sanctum::actingAs($this->makeUser());
        $this->getJson('/api/me/favorite-roasters')
            ->assertOk()
            ->assertExactJson(['items' => []]);
    }

    public function test_user_can_favorite_a_roaster(): void
    {
        $user = $this->makeUser();
        $roaster = $this->makeRoaster();
        Sanctum::actingAs($user);

        $this->postJson('/api/me/favorite-roasters', ['roaster_id' => $roaster->id])
            ->assertCreated()
            ->assertJsonPath('favorite.roaster_id', $roaster->id);

        $this->assertDatabaseHas('roaster_favorites', [
            'user_id' => $user->id, 'roaster_id' => $roaster->id,
        ]);
    }

    public function test_favoriting_twice_is_idempotent(): void
    {
        $user = $this->makeUser();
        $roaster = $this->makeRoaster();
        Sanctum::actingAs($user);

        $this->postJson('/api/me/favorite-roasters', ['roaster_id' => $roaster->id])->assertCreated();
        $this->postJson('/api/me/favorite-roasters', ['roaster_id' => $roaster->id])->assertCreated();

        $this->assertSame(1, RoasterFavorite::where('user_id', $user->id)->count());
    }

    public function test_unknown_roaster_id_is_rejected(): void
    {
        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/me/favorite-roasters', ['roaster_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('roaster_id');
    }

    public function test_index_returns_roaster_summaries_newest_first(): void
    {
        $user = $this->makeUser();
        $first = $this->makeRoaster(['favicon_url' => 'https://example.com/icon.png']);
        $second = $this->makeRoaster();

        RoasterFavorite::create(['user_id' => $user->id, 'roaster_id' => $first->id])
            ->forceFill(['created_at' => now()->subDay()])->save();
        RoasterFavorite::create(['user_id' => $user->id, 'roaster_id' => $second->id]);

        Sanctum::actingAs($user);
        $items = $this->getJson('/api/me/favorite-roasters')->json('items');

        $this->assertCount(2, $items);
        $this->assertSame($second->id, $items[0]['roaster']['id']);
        $this->assertSame($first->id, $items[1]['roaster']['id']);

        foreach (['id', 'name', 'slug', 'favicon_url', 'city', 'region'] as $key) {
            $this->assertArrayHasKey($key, $items[1]['roaster']);
        }
        $this->assertSame('https://example.com/icon.png', $items[1]['roaster']['favicon_url']);
    }

    public function test_index_only_shows_my_own_favorites(): void
    {
        $alice = $this->makeUser('alice@example.com');
        $bob = $this->makeUser('bob@example.com');
        $r1 = $this->makeRoaster();
        $r2 = $this->makeRoaster();

        RoasterFavorite::create(['user_id' => $alice->id, 'roaster_id' => $r1->id]);
        RoasterFavorite::create(['user_id' => $bob->id, 'roaster_id' => $r2->id]);

        Sanctum::actingAs($alice);
        $items = $this->getJson('/api/me/favorite-roasters')->json('items');
        $this->assertCount(1, $items);
        $this->assertSame($r1->id, $items[0]['roaster']['id']);
    }

    public function test_destroy_removes_only_my_entry(): void
    {
        $alice = $this->makeUser('alice@example.com');
        $bob = $this->makeUser('bob@example.com');
        $roaster = $this->makeRoaster();

        RoasterFavorite::create(['user_id' => $alice->id, 'roaster_id' => $roaster->id]);
        RoasterFavorite::create(['user_id' => $bob->id, 'roaster_id' => $roaster->id]);

        Sanctum::actingAs($alice);
        $this->deleteJson("/api/me/favorite-roasters/{$roaster->slug}")->assertNoContent();

        $this->assertDatabaseMissing('roaster_favorites', ['user_id' => $alice->id, 'roaster_id' => $roaster->id]);
        $this->assertDatabaseHas('roaster_favorites', ['user_id' => $bob->id, 'roaster_id' => $roaster->id]);
    }

    public function test_destroy_when_not_favorited_is_no_op(): void
    {
        Sanctum::actingAs($this->makeUser());
        $roaster = $this->makeRoaster();
        $this->deleteJson("/api/me/favorite-roasters/{$roaster->slug}")->assertNoContent();
    }

    public function test_deleting_a_roaster_cascades_the_favorite_rows(): void
    {
        $user = $this->makeUser();
        $roaster = $this->makeRoaster();
        RoasterFavorite::create(['user_id' => $user->id, 'roaster_id' => $roaster->id]);

        $roaster->delete();

        $this->assertDatabaseMissing('roaster_favorites', ['user_id' => $user->id]);
    }
}
