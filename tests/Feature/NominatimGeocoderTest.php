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

    public function test_last_error_reports_a_usage_policy_block_instead_of_no_match(): void
    {
        Http::fake(['*' => Http::response('<html><body>Access blocked</body></html>', 403)]);
        $geocoder = new NominatimGeocoder();

        $this->assertNull($geocoder->geocode('111 Main St', 'Vancouver'));
        $this->assertSame('Nominatim returned HTTP 403: Access blocked', $geocoder->lastError());
    }

    public function test_last_error_is_null_for_a_genuine_no_match(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $geocoder = new NominatimGeocoder();

        $this->assertNull($geocoder->geocode('nowhere', 'Vancouver'));
        $this->assertNull($geocoder->lastError());
    }

    public function test_last_error_resets_between_calls(): void
    {
        Http::fakeSequence()
            ->push('blocked', 403)
            ->push([['lat' => '1', 'lon' => '2', 'display_name' => 'x']], 200);
        $geocoder = new NominatimGeocoder();

        $geocoder->geocode('a', 'b');
        $this->assertNotNull($geocoder->lastError());

        $this->assertNotNull($geocoder->geocode('a', 'b'));
        $this->assertNull($geocoder->lastError());
    }

    public function test_identifies_the_operator_per_nominatim_usage_policy(): void
    {
        config(['app.url' => 'https://api.roastmap.ca', 'services.nominatim.contact_email' => 'ops@roastmap.ca']);
        Http::fake(['*' => Http::response([], 200)]);

        (new NominatimGeocoder())->geocode('111 Main St', 'Vancouver');

        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->hasHeader('User-Agent', 'RoastMap/1.0 (+https://api.roastmap.ca; ops@roastmap.ca)')
                && ($query['email'] ?? null) === 'ops@roastmap.ca';
        });
    }

    public function test_omits_email_param_when_no_contact_is_configured(): void
    {
        config(['app.url' => 'https://api.roastmap.ca', 'services.nominatim.contact_email' => null]);
        Http::fake(['*' => Http::response([], 200)]);

        (new NominatimGeocoder())->geocode('111 Main St', 'Vancouver');

        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->hasHeader('User-Agent', 'RoastMap/1.0 (+https://api.roastmap.ca)')
                && ! array_key_exists('email', $query);
        });
    }

    public function test_returns_null_for_empty_input(): void
    {
        Http::fake();
        $this->assertNull((new NominatimGeocoder())->geocode(''));
    }
}
