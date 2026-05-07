<?php

namespace Tests\Feature;

use App\Models\Coffee;
use App\Models\Roaster;
use App\Models\Tasting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Q17: defensive moderation primitives — anyone can flag a public
 * tasting, admin reviews flagged + can soft-delete to hide from
 * public surfaces. Soft-delete preserves audit trail and is reversible.
 */
class ModerationTest extends TestCase
{
    use RefreshDatabase;

    private function makeCoffee(): Coffee
    {
        $suffix = substr(bin2hex(random_bytes(3)), 0, 6);
        $roaster = Roaster::create(['name' => 'R', 'slug' => 'r-' . $suffix, 'city' => 'Vancouver']);
        return $roaster->coffees()->create(['name' => 'Yirg', 'origin' => 'Ethiopia']);
    }

    private function makeUser(array $overrides = []): User
    {
        $suffix = substr(bin2hex(random_bytes(3)), 0, 6);
        return User::create(array_merge([
            'name' => 'Taster',
            'email' => 'taster_' . $suffix . '@example.com',
            'display_name' => 'taster_' . $suffix,
            'password' => bcrypt('secret-' . $suffix),
        ], $overrides));
    }

    private function makePublicTasting(?User $user = null): Tasting
    {
        $user ??= $this->makeUser();
        return Tasting::create([
            'user_id' => $user->id,
            'coffee_id' => $this->makeCoffee()->id,
            'rating' => 8,
            'notes' => 'lovely cup',
            'tasted_on' => '2026-04-20',
            'is_public' => true,
        ]);
    }

    // ── POST /api/tastings/{id}/report ───────────────────────────────────

    public function test_anyone_can_report_a_public_tasting(): void
    {
        $tasting = $this->makePublicTasting();

        $this->postJson("/api/tastings/{$tasting->id}/report")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $tasting->refresh();
        $this->assertNotNull($tasting->flagged_at);
        $this->assertNull($tasting->flagged_by_user_id, 'unauthenticated reporter has no user id');
    }

    public function test_authenticated_reporter_id_is_recorded(): void
    {
        $tasting = $this->makePublicTasting();
        $reporter = $this->makeUser();
        Sanctum::actingAs($reporter);

        $this->postJson("/api/tastings/{$tasting->id}/report")->assertOk();

        $this->assertSame($reporter->id, $tasting->fresh()->flagged_by_user_id);
    }

    public function test_reporting_is_idempotent(): void
    {
        $tasting = $this->makePublicTasting();

        $this->postJson("/api/tastings/{$tasting->id}/report")->assertOk();
        $first = $tasting->fresh()->flagged_at;
        // Second report should NOT bump flagged_at — keep first-flag timestamp.
        $this->postJson("/api/tastings/{$tasting->id}/report")->assertOk();
        $this->assertEquals($first->toIso8601String(), $tasting->fresh()->flagged_at->toIso8601String());
    }

    public function test_reporting_a_private_tasting_returns_404(): void
    {
        $user = $this->makeUser();
        $tasting = Tasting::create([
            'user_id' => $user->id,
            'coffee_id' => $this->makeCoffee()->id,
            'rating' => 5,
            'tasted_on' => '2026-04-20',
            'is_public' => false,
        ]);

        $this->postJson("/api/tastings/{$tasting->id}/report")->assertStatus(404);
        $this->assertNull($tasting->fresh()->flagged_at);
    }

    // ── soft-deleted hides from public surfaces ──────────────────────────

    public function test_soft_deleted_tasting_disappears_from_public_coffee_feed(): void
    {
        $tasting = $this->makePublicTasting();
        $coffeeId = $tasting->coffee_id;

        $this->getJson("/api/coffees/{$coffeeId}/tastings")
            ->assertOk()
            ->assertJsonCount(1, 'tastings');

        $tasting->delete();

        $this->getJson("/api/coffees/{$coffeeId}/tastings")
            ->assertOk()
            ->assertJsonCount(0, 'tastings');
    }

    public function test_soft_deleted_tasting_404s_on_permalink(): void
    {
        $tasting = $this->makePublicTasting();

        $this->getJson("/api/tastings/{$tasting->id}/public")->assertOk();

        $tasting->delete();

        $this->getJson("/api/tastings/{$tasting->id}/public")->assertStatus(404);
    }

    public function test_soft_deleted_tasting_excluded_from_aggregate_rating(): void
    {
        $coffee = $this->makeCoffee();
        $alice = $this->makeUser();
        $bob = $this->makeUser();

        $bad = Tasting::create([
            'user_id' => $alice->id, 'coffee_id' => $coffee->id,
            'rating' => 1, 'tasted_on' => '2026-04-20', 'is_public' => true,
        ]);
        Tasting::create([
            'user_id' => $bob->id, 'coffee_id' => $coffee->id,
            'rating' => 9, 'tasted_on' => '2026-04-20', 'is_public' => true,
        ]);

        // Before hide: avg = (1+9)/2 = 5.0 (JSON serializes whole-number floats as int)
        $r = $this->getJson("/api/coffees/{$coffee->id}")->json('coffee.rating');
        $this->assertSame(2, $r['count']);
        $this->assertEquals(5.0, $r['average']);

        $bad->delete();

        // After hide: only the 9 remains
        $r = $this->getJson("/api/coffees/{$coffee->id}")->json('coffee.rating');
        $this->assertSame(1, $r['count']);
        $this->assertEquals(9.0, $r['average']);
    }

    // ── rate limit on tasting creation (Q17 rate-limit clause) ───────────

    public function test_new_account_is_rate_limited_to_5_tastings_per_day(): void
    {
        $user = $this->makeUser();
        $coffee = $this->makeCoffee();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/tastings', [
                'coffee_id' => $coffee->id, 'rating' => 8, 'tasted_on' => '2026-04-20',
            ])->assertCreated();
        }

        $this->postJson('/api/tastings', [
            'coffee_id' => $coffee->id, 'rating' => 8, 'tasted_on' => '2026-04-20',
        ])->assertStatus(429);
    }

    public function test_old_account_is_not_rate_limited(): void
    {
        $user = $this->makeUser();
        // Backdate creation so the 7-day window doesn't apply.
        $user->forceFill(['created_at' => now()->subDays(30)])->save();

        $coffee = $this->makeCoffee();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/tastings', [
                'coffee_id' => $coffee->id, 'rating' => 8, 'tasted_on' => '2026-04-20',
            ])->assertCreated();
        }
    }
}
