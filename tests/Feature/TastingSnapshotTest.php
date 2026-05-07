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
 * Coffee state must be frozen at tasting-creation time. The same
 * coffee_id can refer to a totally different bean six months later
 * (roasters rotate seasonal beans through the same Shopify product
 * slot). The user's tasting record should survive that rotation.
 */
class TastingSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        $suffix = substr(bin2hex(random_bytes(3)), 0, 6);
        return User::create([
            'name' => 'Taster',
            'email' => "t_{$suffix}@example.com",
            'display_name' => "taster_{$suffix}",
            'password' => bcrypt('secret'),
        ]);
    }

    private function makeCoffee(array $overrides = []): Coffee
    {
        $suffix = substr(bin2hex(random_bytes(3)), 0, 6);
        $roaster = Roaster::create([
            'name' => 'Phil Sebastian',
            'slug' => "phil-seb-{$suffix}",
            'city' => 'Calgary',
        ]);
        return $roaster->coffees()->create(array_merge([
            'name' => 'Yirgacheffe Konga',
            'origin' => 'Ethiopia, Yirgacheffe',
            'process' => 'Washed',
            'roast_level' => 'light',
            'varietal' => 'Heirloom',
            'tasting_notes' => 'jasmine, bergamot, honey',
            'image_url' => 'https://img.example.com/yirg.jpg',
            'is_blend' => false,
        ], $overrides));
    }

    public function test_create_captures_a_coffee_snapshot(): void
    {
        $user = $this->makeUser();
        $coffee = $this->makeCoffee();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/tastings', [
            'coffee_id' => $coffee->id,
            'rating' => 8,
            'notes' => 'Loved it',
            'tasted_on' => '2026-04-29',
        ]);

        $response->assertCreated();
        $tasting = Tasting::first();
        $this->assertNotNull($tasting->coffee_snapshot);
        $this->assertSame('Yirgacheffe Konga', $tasting->coffee_snapshot['name']);
        $this->assertSame('Washed', $tasting->coffee_snapshot['process']);
        $this->assertSame('jasmine, bergamot, honey', $tasting->coffee_snapshot['tasting_notes']);
        $this->assertSame('Phil Sebastian', $tasting->coffee_snapshot['roaster_name']);
        $this->assertFalse($tasting->coffee_snapshot['is_blend']);
        $this->assertArrayHasKey('snapshotted_at', $tasting->coffee_snapshot);
    }

    public function test_snapshot_survives_when_live_coffee_changes(): void
    {
        $user = $this->makeUser();
        $coffee = $this->makeCoffee();
        Sanctum::actingAs($user);

        $this->postJson('/api/tastings', [
            'coffee_id' => $coffee->id,
            'rating' => 8,
            'tasted_on' => '2026-04-29',
        ])->assertCreated();

        // Roaster rotates the same Shopify product to a new bean
        $coffee->update([
            'name' => 'Yirgacheffe Aricha',
            'process' => 'Natural',
            'tasting_notes' => 'blueberry, dark chocolate',
        ]);

        $response = $this->getJson('/api/tastings');
        $payload = $response->json('tastings.0');

        $this->assertSame('Yirgacheffe Konga', $payload['coffee_snapshot']['name']);
        $this->assertSame('Yirgacheffe Aricha', $payload['coffee']['name']);
        $this->assertTrue($payload['coffee_changed']);
    }

    public function test_coffee_changed_is_false_when_nothing_drifted(): void
    {
        $user = $this->makeUser();
        $coffee = $this->makeCoffee();
        Sanctum::actingAs($user);

        $this->postJson('/api/tastings', [
            'coffee_id' => $coffee->id,
            'rating' => 7,
            'tasted_on' => '2026-04-29',
        ])->assertCreated();

        // Description is excluded from the change check on purpose
        $coffee->update(['description' => 'A new marketing blurb']);

        $payload = $this->getJson('/api/tastings')->json('tastings.0');
        $this->assertFalse($payload['coffee_changed']);
    }

    public function test_legacy_tasting_with_null_snapshot_falls_back_to_live(): void
    {
        $user = $this->makeUser();
        $coffee = $this->makeCoffee();
        Sanctum::actingAs($user);

        // Insert directly without going through the controller — simulates
        // a tasting created before this feature existed.
        Tasting::create([
            'user_id' => $user->id,
            'coffee_id' => $coffee->id,
            'rating' => 6,
            'tasted_on' => '2026-04-15',
            'is_public' => true,
            // no coffee_snapshot
        ]);

        $payload = $this->getJson('/api/tastings')->json('tastings.0');
        $this->assertNull($payload['coffee_snapshot']);
        $this->assertSame('Yirgacheffe Konga', $payload['coffee']['name']);
        $this->assertFalse($payload['coffee_changed']);
    }
}
