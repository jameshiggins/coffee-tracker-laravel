<?php

namespace Tests\Feature\Api;

use App\Models\Coffee;
use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for the prod 500 caused by invalid UTF-8 bytes in
 * scraped coffee names making `json_encode` throw on /api/roasters.
 *
 * Before the fix: a single coffee with a stray Latin-1 byte (0xE9 = 'é'
 * in Latin-1, invalid as UTF-8) blew up the entire endpoint with
 * "Malformed UTF-8 characters, possibly incorrectly encoded".
 *
 * After the fix: JSON_INVALID_UTF8_SUBSTITUTE on the JsonResponse
 * substitutes U+FFFD and the endpoint returns 200 with the rest of the
 * payload intact. (Import-time `Shared::sanitizeUtf8` prevents new bad
 * bytes from entering the DB in the first place; this is the read-side
 * defense for the existing tail.)
 */
class RoasterApiUtf8Test extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_200_when_a_coffee_name_has_invalid_utf8_bytes(): void
    {
        $roaster = Roaster::create([
            'name'         => 'Test Roaster',
            'slug'         => 'test-roaster',
            'city'         => 'Vancouver',
            'region'       => 'British Columbia',
            'country_code' => 'CA',
            'is_active'    => true,
            'has_shipping' => true,
        ]);

        // A lone 0xE9 byte is 'é' in Latin-1 / Windows-1252 but is an
        // invalid UTF-8 sequence on its own. Scraped product feeds
        // occasionally hand back exactly this kind of bad byte.
        Coffee::create([
            'roaster_id' => $roaster->id,
            'name'       => "Bad bytes Caf\xE9 espresso",
            'origin'     => 'Colombia',
            'is_blend'   => false,
        ]);

        $response = $this->getJson('/api/roasters');

        $response->assertStatus(200);
        $response->assertJsonPath('roasters.0.name', 'Test Roaster');

        // Sanity: the response body is a valid JSON string (would not be
        // if the encode threw).
        $this->assertIsString($response->getContent());
        $this->assertNotEmpty($response->getContent());
    }
}
