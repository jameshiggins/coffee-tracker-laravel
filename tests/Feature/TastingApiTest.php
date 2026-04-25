<?php

namespace Tests\Feature;

use App\Models\Coffee;
use App\Models\Roaster;
use App\Models\Tasting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TastingApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeCoffee(): Coffee
    {
        $roaster = Roaster::create(['name' => 'R', 'slug' => 'r', 'city' => 'Vancouver']);
        return $roaster->coffees()->create(['name' => 'Yirg', 'origin' => 'Ethiopia']);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'display_name' => 'alice_taster',
            'password' => bcrypt(\Illuminate\Support\Str::random(32)),
        ], $overrides));
    }

    // ── POST /api/tastings ───────────────────────────────────────────────

    public function test_unauthenticated_post_is_rejected(): void
    {
        $coffee = $this->makeCoffee();
        $this->postJson('/api/tastings', [
            'coffee_id' => $coffee->id,
            'rating' => 8,
            'tasted_on' => '2026-04-24',
        ])->assertUnauthorized();
    }

    public function test_authenticated_user_can_create_a_tasting(): void
    {
        $user = $this->makeUser();
        $coffee = $this->makeCoffee();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/tastings', [
            'coffee_id' => $coffee->id,
            'rating' => 8,
            'notes' => 'Loved the floral notes.',
            'brew_method' => 'v60',
            'tasted_on' => '2026-04-24',
            'is_public' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('tasting.rating', 8)
            ->assertJsonPath('tasting.notes', 'Loved the floral notes.')
            ->assertJsonPath('tasting.is_public', true);

        $this->assertDatabaseHas('tastings', [
            'user_id' => $user->id,
            'coffee_id' => $coffee->id,
            'rating' => 8,
        ]);
    }

    public function test_rating_is_optional(): void
    {
        $user = $this->makeUser();
        $coffee = $this->makeCoffee();
        Sanctum::actingAs($user);

        $this->postJson('/api/tastings', [
            'coffee_id' => $coffee->id,
            'tasted_on' => '2026-04-24',
        ])->assertCreated();
    }

    public function test_rating_outside_1_to_10_is_rejected(): void
    {
        $user = $this->makeUser();
        $coffee = $this->makeCoffee();
        Sanctum::actingAs($user);

        $this->postJson('/api/tastings', ['coffee_id' => $coffee->id, 'rating' => 0, 'tasted_on' => '2026-04-24'])
            ->assertStatus(422);
        $this->postJson('/api/tastings', ['coffee_id' => $coffee->id, 'rating' => 11, 'tasted_on' => '2026-04-24'])
            ->assertStatus(422);
    }

    public function test_non_existent_coffee_is_rejected(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson('/api/tastings', [
            'coffee_id' => 999999,
            'tasted_on' => '2026-04-24',
        ])->assertStatus(422);
    }

    public function test_is_public_defaults_to_true_when_omitted(): void
    {
        $user = $this->makeUser();
        $coffee = $this->makeCoffee();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/tastings', [
            'coffee_id' => $coffee->id,
            'tasted_on' => '2026-04-24',
        ]);

        $response->assertCreated()->assertJsonPath('tasting.is_public', true);
    }

    // ── GET /api/tastings (my own) ───────────────────────────────────────

    public function test_unauthenticated_my_tastings_is_rejected(): void
    {
        $this->getJson('/api/tastings')->assertUnauthorized();
    }

    public function test_my_tastings_returns_only_my_own(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser(['email' => 'bob@example.com', 'name' => 'Bob']);
        $coffee = $this->makeCoffee();

        Tasting::create(['user_id' => $alice->id, 'coffee_id' => $coffee->id, 'rating' => 8, 'tasted_on' => '2026-04-20']);
        Tasting::create(['user_id' => $alice->id, 'coffee_id' => $coffee->id, 'rating' => 6, 'tasted_on' => '2026-04-21', 'is_public' => false]);
        Tasting::create(['user_id' => $bob->id, 'coffee_id' => $coffee->id, 'rating' => 9, 'tasted_on' => '2026-04-22']);

        Sanctum::actingAs($alice);
        $response = $this->getJson('/api/tastings');

        $response->assertOk();
        $tastings = $response->json('tastings');
        $this->assertCount(2, $tastings, 'should include my private tasting too');
    }

    // ── GET /api/coffees/{coffee}/tastings (public feed) ─────────────────

    public function test_public_coffee_tastings_excludes_private_ones(): void
    {
        $alice = $this->makeUser();
        $coffee = $this->makeCoffee();

        Tasting::create(['user_id' => $alice->id, 'coffee_id' => $coffee->id, 'rating' => 8, 'tasted_on' => '2026-04-20', 'is_public' => true]);
        Tasting::create(['user_id' => $alice->id, 'coffee_id' => $coffee->id, 'rating' => 6, 'tasted_on' => '2026-04-21', 'is_public' => false]);

        $response = $this->getJson("/api/coffees/{$coffee->id}/tastings");

        $response->assertOk();
        $this->assertCount(1, $response->json('tastings'));
        $this->assertSame(8, $response->json('tastings.0.rating'));
    }

    public function test_public_coffee_tastings_works_unauthenticated(): void
    {
        $coffee = $this->makeCoffee();
        $this->getJson("/api/coffees/{$coffee->id}/tastings")->assertOk();
    }

    // ── PUT /api/tastings/{id} ───────────────────────────────────────────

    public function test_user_can_update_their_own_tasting(): void
    {
        $user = $this->makeUser();
        $coffee = $this->makeCoffee();
        $tasting = Tasting::create(['user_id' => $user->id, 'coffee_id' => $coffee->id, 'rating' => 5, 'tasted_on' => '2026-04-20']);

        Sanctum::actingAs($user);
        $this->putJson("/api/tastings/{$tasting->id}", ['rating' => 9])
            ->assertOk()
            ->assertJsonPath('tasting.rating', 9);
    }

    public function test_user_cannot_update_someone_elses_tasting(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser(['email' => 'bob@example.com']);
        $coffee = $this->makeCoffee();
        $tasting = Tasting::create(['user_id' => $alice->id, 'coffee_id' => $coffee->id, 'rating' => 5, 'tasted_on' => '2026-04-20']);

        Sanctum::actingAs($bob);
        $this->putJson("/api/tastings/{$tasting->id}", ['rating' => 1])->assertForbidden();
        $this->assertSame(5, $tasting->fresh()->rating);
    }

    // ── DELETE /api/tastings/{id} ────────────────────────────────────────

    public function test_user_can_delete_their_own_tasting(): void
    {
        $user = $this->makeUser();
        $coffee = $this->makeCoffee();
        $tasting = Tasting::create(['user_id' => $user->id, 'coffee_id' => $coffee->id, 'tasted_on' => '2026-04-20']);

        Sanctum::actingAs($user);
        $this->deleteJson("/api/tastings/{$tasting->id}")->assertNoContent();
        $this->assertDatabaseMissing('tastings', ['id' => $tasting->id]);
    }

    public function test_user_cannot_delete_someone_elses_tasting(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser(['email' => 'bob@example.com']);
        $coffee = $this->makeCoffee();
        $tasting = Tasting::create(['user_id' => $alice->id, 'coffee_id' => $coffee->id, 'tasted_on' => '2026-04-20']);

        Sanctum::actingAs($bob);
        $this->deleteJson("/api/tastings/{$tasting->id}")->assertForbidden();
        $this->assertDatabaseHas('tastings', ['id' => $tasting->id]);
    }

    // ── GET /api/me ──────────────────────────────────────────────────────

    public function test_me_endpoint_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_me_endpoint_returns_current_user(): void
    {
        $user = $this->makeUser(['display_name' => 'alice_taster', 'avatar_url' => 'https://example.com/a.jpg']);
        Sanctum::actingAs($user);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'alice@example.com')
            ->assertJsonPath('user.display_name', 'alice_taster')
            ->assertJsonPath('user.avatar_url', 'https://example.com/a.jpg');
    }
}
