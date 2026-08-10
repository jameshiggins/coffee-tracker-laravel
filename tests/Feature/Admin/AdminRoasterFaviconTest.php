<?php

namespace Tests\Feature\Admin;

use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin "Logo URL (override)" field. The favicon scraper only backfills
 * empty favicon_url values, so this override is the operator's escape hatch
 * for roasters whose scraped icon is tiny, wrong, or unreadable in dark mode.
 */
class AdminRoasterFaviconTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name'   => 'Test Roaster',
            'region' => 'Vancouver',
            'city'   => 'Vancouver',
        ], $overrides);
    }

    public function test_update_persists_favicon_url_override(): void
    {
        $roaster = Roaster::factory()->create(['favicon_url' => null]);

        $this->actingAsAdmin()
            ->put(route('admin.roasters.update', $roaster), $this->validPayload([
                'favicon_url' => 'https://cdn.example.com/logo-512.png',
            ]))
            ->assertRedirect(route('admin.roasters.index'));

        $this->assertSame(
            'https://cdn.example.com/logo-512.png',
            $roaster->fresh()->favicon_url
        );
    }

    public function test_update_can_clear_favicon_url(): void
    {
        $roaster = Roaster::factory()->create(['favicon_url' => 'https://old.example.com/icon.png']);

        $this->actingAsAdmin()
            ->put(route('admin.roasters.update', $roaster), $this->validPayload([
                'favicon_url' => '',
            ]))
            ->assertRedirect(route('admin.roasters.index'));

        $this->assertNull($roaster->fresh()->favicon_url);
    }

    public function test_update_rejects_non_url_favicon(): void
    {
        $roaster = Roaster::factory()->create(['favicon_url' => null]);

        $this->actingAsAdmin()
            ->from(route('admin.roasters.edit', $roaster))
            ->put(route('admin.roasters.update', $roaster), $this->validPayload([
                'favicon_url' => 'not-a-url',
            ]))
            ->assertSessionHasErrors('favicon_url');

        $this->assertNull($roaster->fresh()->favicon_url);
    }

    public function test_store_accepts_favicon_url(): void
    {
        $this->actingAsAdmin()
            ->post(route('admin.roasters.store'), $this->validPayload([
                'name'        => 'New Roaster',
                'favicon_url' => 'https://cdn.example.com/logo.png',
            ]))
            ->assertRedirect(route('admin.roasters.index'));

        $this->assertSame(
            'https://cdn.example.com/logo.png',
            Roaster::where('slug', 'new-roaster')->firstOrFail()->favicon_url
        );
    }

    public function test_edit_form_shows_favicon_field(): void
    {
        $roaster = Roaster::factory()->create();

        $this->actingAsAdmin()
            ->get(route('admin.roasters.edit', $roaster))
            ->assertOk()
            ->assertSee('favicon_url');
    }
}
