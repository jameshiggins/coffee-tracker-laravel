<?php

namespace Tests\Feature\Scraping;

use App\Models\Roaster;
use App\Services\Scraping\Address\AddressScraper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Integration test for the address-resolution cascade orchestrator. Verifies:
 *   - Step-1 hit returns immediately and no later steps fire HTTP
 *   - Step-2 fallback (contact-page) when JSON-LD missing
 *   - Step-3 fallback (Nominatim name search)
 *   - All-null fallback returns null (caller handles is_online_only)
 *   - Google Places step (step 4) is skipped gracefully when key unset
 */
class AddressScraperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Default: Google Places off. Individual tests override as needed.
        config(['services.google_places.key' => null]);
    }

    private function roaster(array $overrides = []): Roaster
    {
        return Roaster::create(array_merge([
            'name' => 'Test Roaster',
            'slug' => 'test-roaster',
            'city' => 'Vancouver',
            'region' => 'BC',
            'country_code' => 'CA',
            'website' => 'https://test-roaster.example.com',
            'is_active' => true,
        ], $overrides));
    }

    private function jsonLdHtml(): string
    {
        return '<html><head><script type="application/ld+json">'
            . json_encode([
                '@type' => 'LocalBusiness',
                'name' => 'Test Roaster',
                'address' => [
                    'streetAddress' => '100 JSON-LD Blvd',
                    'addressLocality' => 'Vancouver',
                    'addressRegion' => 'BC',
                    'postalCode' => 'V6B 1A1',
                ],
            ])
            . '</script></head><body></body></html>';
    }

    public function test_step_1_jsonld_hit_returns_early(): void
    {
        Http::fake([
            '*test-roaster.example.com*' => Http::response($this->jsonLdHtml(), 200),
            // Other URLs would still respond — but we shouldn't call Nominatim.
            '*' => Http::response('<html></html>', 200),
        ]);

        $roaster = $this->roaster();
        $result = (new AddressScraper())->scrape($roaster);

        $this->assertNotNull($result);
        $this->assertSame('jsonld', $result->source);
        $this->assertSame('100 JSON-LD Blvd', $result->street_address);

        // The cascade stopped at the FIRST candidate path. The next paths
        // (e.g. /contact, /contact-us, /visit) should never have been
        // fetched. NominatimGeocoder::geocode() may still run as step 5 to
        // translate the JSON-LD address into lat/lng — but the NAME-based
        // search (step 3) must NOT fire.
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/contact'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/visit'));
        Http::assertNotSent(fn ($req) => str_contains($req->url(), '/locations'));
    }

    public function test_step_2_contact_page_fallback_when_jsonld_missing(): void
    {
        $contactHtml = '<html><body><address>'
            . '200 Contact Way<br>Toronto, ON M5V 1L4'
            . '</address></body></html>';

        // Homepage and most candidates return blank pages; only /contact
        // carries the address.
        Http::fake([
            '*test-roaster.example.com/contact' => Http::response($contactHtml, 200),
            '*test-roaster.example.com*' => Http::response('<html></html>', 200),
        ]);

        $result = (new AddressScraper())->scrape($this->roaster());

        $this->assertNotNull($result);
        $this->assertSame('website', $result->source);
        $this->assertSame('200 Contact Way', $result->street_address);
        $this->assertSame('M5V 1L4', $result->postal_code);
    }

    public function test_step_3_nominatim_fallback_when_pages_yield_nothing(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([
                [
                    'lat' => '49.2607', 'lon' => '-123.1140',
                    'display_name' => 'Test Roaster, 300 OSM St, Vancouver',
                    'address' => [
                        'house_number' => '300', 'road' => 'OSM St',
                        'city' => 'Vancouver', 'state' => 'British Columbia',
                        'postcode' => 'V6B 2B2', 'country_code' => 'ca',
                    ],
                ],
            ], 200),
            // Every roaster page is empty / address-less.
            '*' => Http::response('<html><body>nothing here</body></html>', 200),
        ]);

        $result = (new AddressScraper())->scrape($this->roaster());

        $this->assertNotNull($result);
        $this->assertSame('osm', $result->source);
        $this->assertSame('300 OSM St', $result->street_address);
        $this->assertSame(49.2607, $result->latitude);
        $this->assertSame(-123.1140, $result->longitude);
    }

    public function test_returns_null_when_every_step_fails(): void
    {
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 200),
            '*' => Http::response('<html><body>nothing</body></html>', 200),
        ]);

        $result = (new AddressScraper())->scrape($this->roaster());

        $this->assertNull($result);
    }

    public function test_google_places_step_is_skipped_when_key_unset(): void
    {
        // Configure no JSON-LD, no contact match, no Nominatim hit. Google
        // Places stub would throw if called — verify the cascade skips it.
        config(['services.google_places.key' => null]);
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 200),
            '*' => Http::response('<html></html>', 200),
        ]);

        // Should NOT throw despite Google Places resolver being unconfigured.
        $result = (new AddressScraper())->scrape($this->roaster());

        $this->assertNull($result);
    }

    public function test_already_resolved_roaster_is_skipped_without_force(): void
    {
        // Roaster has a verified address. The cascade should leave it alone
        // and fire NO HTTP unless --force is requested.
        Http::fake();

        $roaster = $this->roaster([
            'address_source' => 'manual',
            'street_address' => '999 Manual St',
        ]);

        $scraper = new AddressScraper();
        $result = $scraper->scrape($roaster);

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_force_overrides_already_resolved(): void
    {
        Http::fake([
            '*test-roaster.example.com*' => Http::response($this->jsonLdHtml(), 200),
            '*' => Http::response('<html></html>', 200),
        ]);

        $roaster = $this->roaster([
            'address_source' => 'manual',
            'street_address' => '999 Old',
        ]);

        $result = (new AddressScraper())->scrape($roaster, force: true);

        $this->assertNotNull($result);
        $this->assertSame('jsonld', $result->source);
    }
}
