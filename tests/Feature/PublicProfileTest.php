<?php

namespace Tests\Feature;

use App\Models\Coffee;
use App\Models\Roaster;
use App\Models\Tasting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicProfileTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::create([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'display_name' => 'alice_taster',
            'password' => bcrypt('whatever123'),
        ]);
        $roaster = Roaster::create(['name' => 'Sey', 'slug' => 'sey', 'city' => 'Brooklyn']);
        $coffee = $roaster->coffees()->create(['name' => 'Yirg', 'origin' => 'Ethiopia']);

        $publicTasting = Tasting::create([
            'user_id' => $user->id, 'coffee_id' => $coffee->id, 'rating' => 9,
            'notes' => 'Bright', 'tasted_on' => '2026-04-01', 'is_public' => true,
        ]);
        $privateTasting = Tasting::create([
            'user_id' => $user->id, 'coffee_id' => $coffee->id, 'rating' => 4,
            'notes' => 'Off', 'tasted_on' => '2026-04-02', 'is_public' => false,
        ]);
        return compact('user', 'coffee', 'publicTasting', 'privateTasting');
    }

    // ── /api/users/{display_name} ────────────────────────────────────────

    public function test_profile_returns_user_and_only_their_public_tastings(): void
    {
        ['user' => $user] = $this->fixture();

        $r = $this->getJson('/api/users/alice_taster');

        $r->assertOk()
            ->assertJsonPath('user.display_name', 'alice_taster')
            ->assertJsonCount(1, 'tastings'); // private one excluded
    }

    public function test_profile_404_for_unknown_display_name(): void
    {
        $this->getJson('/api/users/does-not-exist')->assertNotFound();
    }

    // ── /api/tastings/{id}/public ────────────────────────────────────────

    public function test_tasting_permalink_returns_public_tasting_with_user(): void
    {
        ['publicTasting' => $t] = $this->fixture();

        $r = $this->getJson("/api/tastings/{$t->id}/public");
        $r->assertOk()
            ->assertJsonPath('tasting.id', $t->id)
            ->assertJsonPath('tasting.user.display_name', 'alice_taster')
            ->assertJsonPath('tasting.coffee.name', 'Yirg');
    }

    public function test_tasting_permalink_404s_for_private_tasting(): void
    {
        ['privateTasting' => $t] = $this->fixture();

        $this->getJson("/api/tastings/{$t->id}/public")->assertNotFound();
    }

    // ── display_name uniqueness on registration ──────────────────────────

    public function test_register_rejects_duplicate_display_name(): void
    {
        User::create([
            'name' => 'Bob', 'email' => 'bob@example.com',
            'display_name' => 'bob_taster', 'password' => bcrypt('x'),
        ]);

        $r = $this->postJson('/api/auth/register', [
            'name' => 'Other Bob', 'email' => 'other@example.com',
            'display_name' => 'bob_taster',
            'password' => 'whatever123', 'password_confirmation' => 'whatever123',
        ]);
        $r->assertStatus(422)->assertJsonValidationErrors(['display_name']);
    }

    public function test_register_rejects_invalid_display_name_chars(): void
    {
        $r = $this->postJson('/api/auth/register', [
            'name' => 'Bob', 'email' => 'bob@example.com',
            'display_name' => 'bob taster', // space
            'password' => 'whatever123', 'password_confirmation' => 'whatever123',
        ]);
        $r->assertStatus(422)->assertJsonValidationErrors(['display_name']);
    }

    public function test_register_synthesises_display_name_when_omitted(): void
    {
        $r = $this->postJson('/api/auth/register', [
            'name' => 'Carol Smith', 'email' => 'carol@example.com',
            'password' => 'whatever123', 'password_confirmation' => 'whatever123',
        ]);
        $r->assertCreated();
        $user = User::where('email', 'carol@example.com')->first();
        $this->assertNotNull($user->display_name);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $user->display_name);
    }
}
