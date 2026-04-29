<?php

namespace Tests\Feature;

use App\Services\NominatimGeocoder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NominatimGeocoderTest extends TestCase
{
    public function test_returns_lat_lng_from_first_match(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                ['lat' => '49.2607', 'lon' => '-123.1140', 'display_name' => '111 Main St, Vancouver, BC'],
            ], 200),
        ]);

        $result = (new NominatimGeocoder())->geocode('111 Main St', 'Vancouver', 'BC', 'Canada');

        $this->assertSame(49.2607, $result['lat']);
        $this->assertSame(-123.1140, $result['lng']);
        $this->assertStringContainsString('Vancouver', $result['display_name']);
    }

    public function test_returns_null_when_no_results(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->assertNull((new NominatimGeocoder())->geocode('a', 'b'));
    }

    public function test_returns_null_on_http_error(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $this->assertNull((new NominatimGeocoder())->geocode('a', 'b'));
    }

    public function test_returns_null_for_empty_input(): void
    {
        Http::fake();
        $this->assertNull((new NominatimGeocoder())->geocode(''));
    }
}
