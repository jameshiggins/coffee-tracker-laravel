<?php

namespace Tests\Feature\Admin;

use App\Models\Coffee;
use App\Models\CoffeeVariant;
use App\Models\Roaster;
use App\Models\Tasting;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1 regression guard: the admin "delete coffee / roaster" actions must
 * SOFT-remove, never hard-delete. A hard delete cascades through
 * coffees.roaster_id / tastings.coffee_id / wishlists.coffee_id and
 * irreversibly destroys user-generated content — the exact data loss the
 * soft-remove import design exists to prevent.
 */
class AdminDestroyPreservesDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['admin.user' => 'operator', 'admin.pass' => 'sekret']);
    }

    private function asAdmin()
    {
        return $this->withHeaders([
            'Authorization' => 'Basic ' . base64_encode('operator:sekret'),
        ]);
    }

    public function test_deleting_a_coffee_soft_removes_it_and_preserves_user_data(): void
    {
        $roaster = Roaster::factory()->create();
        $coffee = Coffee::factory()->for($roaster)->create();
        CoffeeVariant::factory()->for($coffee)->create();
        $user = User::factory()->create();
        $tasting = Tasting::factory()->for($user)->for($coffee)->create();
        $wishlist = Wishlist::factory()->for($user)->for($coffee)->create();

        $this->asAdmin()
            ->delete("/admin/roasters/{$roaster->slug}/coffees/{$coffee->id}")
            ->assertRedirect();

        // Coffee still exists, just soft-removed from the directory.
        $this->assertDatabaseHas('coffees', ['id' => $coffee->id]);
        $this->assertNotNull($coffee->fresh()->removed_at);

        // User-generated content is untouched (NOT cascade-deleted).
        $this->assertDatabaseHas('tastings', ['id' => $tasting->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('wishlists', ['id' => $wishlist->id]);
        $this->assertNotNull($tasting->fresh());
    }

    public function test_deleting_a_roaster_deactivates_it_and_preserves_everything(): void
    {
        $roaster = Roaster::factory()->create(['is_active' => true]);
        $coffee = Coffee::factory()->for($roaster)->create();
        $user = User::factory()->create();
        $tasting = Tasting::factory()->for($user)->for($coffee)->create();

        $this->asAdmin()
            ->delete("/admin/roasters/{$roaster->slug}")
            ->assertRedirect(route('admin.roasters.index'));

        // Roaster + its coffees + the tasting all survive; roaster is just hidden.
        $this->assertDatabaseHas('roasters', ['id' => $roaster->id]);
        $this->assertFalse($roaster->fresh()->is_active);
        $this->assertDatabaseHas('coffees', ['id' => $coffee->id]);
        $this->assertDatabaseHas('tastings', ['id' => $tasting->id, 'deleted_at' => null]);
    }

    public function test_restore_brings_a_removed_coffee_back(): void
    {
        $roaster = Roaster::factory()->create();
        $coffee = Coffee::factory()->for($roaster)->removed()->create();

        $this->asAdmin()
            ->post("/admin/roasters/{$roaster->slug}/coffees/{$coffee->id}/restore")
            ->assertRedirect();

        $this->assertNull($coffee->fresh()->removed_at);
    }

    public function test_admin_destroy_requires_authentication(): void
    {
        $roaster = Roaster::factory()->create();
        $coffee = Coffee::factory()->for($roaster)->create();

        // No Basic auth header → blocked, and nothing mutated.
        $this->delete("/admin/roasters/{$roaster->slug}/coffees/{$coffee->id}")
            ->assertStatus(401);

        $this->assertNull($coffee->fresh()->removed_at);
    }
}
