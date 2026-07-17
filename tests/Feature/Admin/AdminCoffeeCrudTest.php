<?php

namespace Tests\Feature\Admin;

use App\Models\Coffee;
use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The manual data-entry path: admin coffee create/store/edit/update. These
 * routes bypass the import pipeline's normalization, so validation here is
 * the only thing standing between a typo'd form and bad catalog rows.
 * (Destroy/restore soft-remove semantics live in AdminDestroyPreservesDataTest.)
 */
class AdminCoffeeCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_form_renders(): void
    {
        $roaster = Roaster::factory()->create();

        $this->actingAsAdmin()
            ->get("/admin/roasters/{$roaster->slug}/coffees/create")
            ->assertOk();
    }

    public function test_store_creates_coffee_and_redirects_to_edit(): void
    {
        $roaster = Roaster::factory()->create();

        $response = $this->actingAsAdmin()->post("/admin/roasters/{$roaster->slug}/coffees", [
            'name' => 'Huila Reserve',
            'origin' => 'Colombia',
            'process' => 'Washed',
            'roast_level' => 'Light',
            'varietal' => 'Caturra',
            'tasting_notes' => 'Cherry, cola',
        ]);

        $coffee = Coffee::where('name', 'Huila Reserve')->firstOrFail();
        $response->assertRedirect(route('admin.coffees.edit', [$roaster, $coffee]));

        $this->assertSame($roaster->id, $coffee->roaster_id);
        $this->assertSame('Colombia', $coffee->origin);
        $this->assertSame('Cherry, cola', $coffee->tasting_notes);
        $this->assertNull($coffee->removed_at);

        // Admin mutations must land in the audit trail.
        $this->assertDatabaseHas('admin_logs', ['event' => 'admin.coffee.created']);
    }

    public function test_store_requires_name_and_origin(): void
    {
        $roaster = Roaster::factory()->create();

        $this->actingAsAdmin()
            ->from("/admin/roasters/{$roaster->slug}/coffees/create")
            ->post("/admin/roasters/{$roaster->slug}/coffees", [
                'process' => 'Washed',
            ])
            ->assertSessionHasErrors(['name', 'origin']);

        $this->assertDatabaseCount('coffees', 0);
    }

    public function test_store_rejects_overlong_fields(): void
    {
        $roaster = Roaster::factory()->create();

        $this->actingAsAdmin()
            ->post("/admin/roasters/{$roaster->slug}/coffees", [
                'name' => str_repeat('a', 256),
                'origin' => 'Colombia',
                'process' => str_repeat('b', 101),
            ])
            ->assertSessionHasErrors(['name', 'process']);
    }

    public function test_edit_form_renders(): void
    {
        $roaster = Roaster::factory()->create();
        $coffee = Coffee::factory()->for($roaster)->create();

        $this->actingAsAdmin()
            ->get("/admin/roasters/{$roaster->slug}/coffees/{$coffee->id}/edit")
            ->assertOk();
    }

    public function test_update_changes_fields_and_logs(): void
    {
        $roaster = Roaster::factory()->create();
        $coffee = Coffee::factory()->for($roaster)->create([
            'name' => 'Old Name', 'origin' => 'Kenya', 'roast_level' => 'Dark',
        ]);

        $this->actingAsAdmin()
            ->put("/admin/roasters/{$roaster->slug}/coffees/{$coffee->id}", [
                'name' => 'New Name',
                'origin' => 'Ethiopia',
                'roast_level' => 'Light',
            ])
            ->assertRedirect(route('admin.coffees.edit', [$roaster, $coffee]));

        $coffee->refresh();
        $this->assertSame('New Name', $coffee->name);
        $this->assertSame('Ethiopia', $coffee->origin);
        $this->assertSame('Light', $coffee->roast_level);

        $this->assertDatabaseHas('admin_logs', ['event' => 'admin.coffee.updated']);
    }

    public function test_update_validation_leaves_row_untouched(): void
    {
        $roaster = Roaster::factory()->create();
        $coffee = Coffee::factory()->for($roaster)->create(['name' => 'Keep Me']);

        $this->actingAsAdmin()
            ->put("/admin/roasters/{$roaster->slug}/coffees/{$coffee->id}", [
                'name' => '', // required
                'origin' => 'Ethiopia',
            ])
            ->assertSessionHasErrors(['name']);

        $this->assertSame('Keep Me', $coffee->fresh()->name);
    }

    public function test_store_requires_admin_authentication(): void
    {
        config(['admin.user' => 'operator', 'admin.pass' => 'sekret']);
        $roaster = Roaster::factory()->create();

        $this->post("/admin/roasters/{$roaster->slug}/coffees", [
            'name' => 'Sneaky Bean', 'origin' => 'Colombia',
        ])->assertRedirect(route('admin.login'));

        $this->assertDatabaseCount('coffees', 0);
    }
}
