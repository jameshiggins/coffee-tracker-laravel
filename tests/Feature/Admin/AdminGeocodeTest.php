<?php

namespace Tests\Feature\Admin;

use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The admin "Geocode" button. The failure path matters most: a Nominatim
 * usage-policy block (403) or rate limit (429) must surface as such, not as
 * "no match" — the operator's fix (contact email / wait) is different from
 * the fix for a bad street address.
 */
class AdminGeocodeTest extends TestCase
{
    use RefreshDatabase;

    private function roaster(): Roaster
    {
        return Roaster::factory()->create([
            'street_address' => '111 Main St', 'city' => 'Vancouver', 'region' => 'BC',
            'latitude' => null, 'longitude' => null,
        ]);
    }

    public function test_success_stores_coordinates_and_marks_the_pin_manual(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            ['lat' => '49.2827', 'lon' => '-123.1207', 'display_name' => '111 Main St, Vancouver'],
        ], 200)]);
        $roaster = $this->roaster();

        $this->actingAsAdmin()
            ->post("/admin/roasters/{$roaster->slug}/geocode")
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, '111 Main St, Vancouver'));

        $roaster->refresh();
        $this->assertSame(49.2827, (float) $roaster->latitude);
        $this->assertSame(-123.1207, (float) $roaster->longitude);
        $this->assertSame('manual', $roaster->address_source);
        $this->assertNotNull($roaster->address_verified_at);
        $this->assertDatabaseHas('admin_logs', ['event' => 'admin.roaster.geocoded']);
    }

    public function test_nominatim_block_is_reported_as_the_reason_not_as_no_match(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response('Access blocked', 403)]);
        $roaster = $this->roaster();

        $this->actingAsAdmin()
            ->post("/admin/roasters/{$roaster->slug}/geocode")
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Nominatim returned HTTP 403'));

        $this->assertNull($roaster->fresh()->latitude);
        $this->assertDatabaseHas('admin_logs', [
            'event' => 'admin.roaster.geocode_failed',
            'message' => "Geocode failed for {$roaster->name}: Nominatim returned HTTP 403: Access blocked",
        ]);
    }

    public function test_genuine_no_match_still_says_so(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([], 200)]);
        $roaster = $this->roaster();

        $this->actingAsAdmin()
            ->post("/admin/roasters/{$roaster->slug}/geocode")
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'no match for that address'));
    }

    public function test_requires_a_street_address(): void
    {
        Http::fake();
        $roaster = Roaster::factory()->create(['street_address' => null]);

        $this->actingAsAdmin()
            ->post("/admin/roasters/{$roaster->slug}/geocode")
            ->assertSessionHasErrors(['street_address']);

        Http::assertNothingSent();
    }
}
