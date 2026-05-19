<?php

namespace Tests\Feature;

use App\Services\NominatimGeocoder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Step 3 of the address cascade: search Nominatim by roaster NAME + city,
 * accept the first result, return an address + lat/lng. Distinct from the
 * existing geocode() which takes a free-form street address.
 */
class NominatimSearchByNameTest extends TestCase
{
    public function test_search_by_name_returns_address_and_lat_lng(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                [
                    'lat' => '49.2607',
                    'lon' => '-123.1140',
                    'display_name' => 'Acme Coffee, 123 Main St, Vancouver, BC, V6B 1A1, Canada',
                    'address' => [
                        'house_number' => '123',
                        'road' => 'Main St',
                        'city' => 'Vancouver',
                        'state' => 'British Columbia',
                        'postcode' => 'V6B 1A1',
                        'country_code' => 'ca',
                    ],
                ],
            ], 200),
        ]);

        $address = (new NominatimGeocoder())->searchByName('Acme Coffee', 'Vancouver');

        $this->assertNotNull($address);
        $this->assertSame('osm', $address->source);
        $this->assertSame('123 Main St', $address->street_address);
        $this->assertSame('Vancouver', $address->city);
        $this->assertSame('V6B 1A1', $address->postal_code);
        $this->assertSame(49.2607, $address->latitude);
        $this->assertSame(-123.1140, $address->longitude);
    }

    public function test_search_by_name_returns_null_when_no_results(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $this->assertNull((new NominatimGeocoder())->searchByName('Nonexistent Coffee', 'Nowhere'));
    }

    public function test_search_by_name_returns_null_on_http_error(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $this->assertNull((new NominatimGeocoder())->searchByName('Acme Coffee', 'Vancouver'));
    }

    public function test_search_by_name_makes_exactly_one_http_call(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 200),
        ]);

        (new NominatimGeocoder())->searchByName('Acme Coffee', 'Vancouver');

        Http::assertSentCount(1);
    }

    public function test_search_by_name_returns_address_even_without_house_number(): void
    {
        // Some OSM rows omit house_number — accept and use just the road.
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                [
                    'lat' => '49.0',
                    'lon' => '-123.0',
                    'display_name' => 'Some Roaster, Main St, Vancouver',
                    'address' => [
                        'road' => 'Main St',
                        'city' => 'Vancouver',
                        'state' => 'British Columbia',
                        'country_code' => 'ca',
                    ],
                ],
            ], 200),
        ]);

        // Without a house number, the row resolves but we treat it as
        // city-level and return null (we already have city centroids).
        $this->assertNull((new NominatimGeocoder())->searchByName('Some Roaster', 'Vancouver'));
    }
}
