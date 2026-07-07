<?php

namespace Tests\Feature;

use App\Models\Roaster;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deactivating a roaster is a moderation "hide": H5 made the roaster and
 * coffee detail endpoints 404, but the public tastings sub-resource of a
 * hidden roaster's coffee kept serving content (2026-07 review P3). Every
 * public read of a hidden roaster's data must behave the same way.
 */
class ModerationConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private function coffeeWithPublicTasting(bool $roasterActive): int
    {
        $roaster = Roaster::create([
            'name' => 'Mod Roaster', 'slug' => 'mod-roaster-'.($roasterActive ? 'on' : 'off'),
            'city' => 'Vancouver', 'is_active' => $roasterActive, 'has_shipping' => true,
        ]);
        $coffee = $roaster->coffees()->create(['name' => 'Yirg', 'origin' => 'Ethiopia', 'is_blend' => false]);
        $user = User::create([
            'name' => 'T', 'email' => 'taster-'.$coffee->id.'@example.com',
            'display_name' => 'taster_'.$coffee->id, 'password' => bcrypt('x'),
        ]);
        $coffee->tastings()->create([
            'user_id' => $user->id, 'rating' => 8, 'is_public' => true,
            'tasted_on' => now()->toDateString(),
        ]);

        return $coffee->id;
    }

    public function test_public_tastings_of_a_hidden_roasters_coffee_404(): void
    {
        $hidden = $this->coffeeWithPublicTasting(roasterActive: false);
        $visible = $this->coffeeWithPublicTasting(roasterActive: true);

        $this->getJson("/api/coffees/{$hidden}/tastings")->assertNotFound();
        $this->getJson("/api/coffees/{$visible}/tastings")
            ->assertOk()
            ->assertJsonCount(1, 'tastings');
    }
}
