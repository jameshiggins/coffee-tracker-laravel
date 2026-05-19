<?php

namespace Tests\Unit\Scraping\Address;

use App\Services\Scraping\Address\JsonLdAddressExtractor;
use App\Services\Scraping\Address\ScrapedAddress;
use Tests\TestCase;

/**
 * Step 1 of the address-resolution cascade: extract a PostalAddress from a
 * schema.org LocalBusiness / Cafe (or any of its subtypes) JSON-LD block.
 *
 * These are pure parsing tests over a string of HTML — no HTTP. Network
 * orchestration (which paths to fetch, when to stop) lives in AddressScraper.
 */
class JsonLdAddressExtractorTest extends TestCase
{
    public function test_extracts_address_from_simple_local_business(): void
    {
        $html = $this->withJsonLd([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => 'Acme Coffee',
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => '123 Main St',
                'addressLocality' => 'Vancouver',
                'addressRegion' => 'BC',
                'postalCode' => 'V6B 1A1',
                'addressCountry' => 'CA',
            ],
        ]);

        $result = (new JsonLdAddressExtractor())->extract($html);

        $this->assertInstanceOf(ScrapedAddress::class, $result);
        $this->assertSame('jsonld', $result->source);
        $this->assertSame('123 Main St', $result->street_address);
        $this->assertSame('Vancouver', $result->city);
        $this->assertSame('BC', $result->region);
        $this->assertSame('V6B 1A1', $result->postal_code);
    }

    public function test_extracts_address_when_type_is_cafe(): void
    {
        $html = $this->withJsonLd([
            '@context' => 'https://schema.org',
            '@type' => 'Cafe',
            'name' => 'Le Cafe',
            'address' => [
                'streetAddress' => '456 King St W',
                'addressLocality' => 'Toronto',
                'addressRegion' => 'ON',
                'postalCode' => 'M5V 1L4',
            ],
        ]);

        $result = (new JsonLdAddressExtractor())->extract($html);

        $this->assertNotNull($result);
        $this->assertSame('456 King St W', $result->street_address);
        $this->assertSame('Toronto', $result->city);
    }

    public function test_extracts_address_from_at_graph_array(): void
    {
        $html = $this->withJsonLd([
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'Organization', 'name' => 'Acme Inc.'],
                [
                    '@type' => 'LocalBusiness',
                    'name' => 'Acme Coffee',
                    'address' => [
                        '@type' => 'PostalAddress',
                        'streetAddress' => '789 Elm Ave',
                        'addressLocality' => 'Montreal',
                        'addressRegion' => 'QC',
                        'postalCode' => 'H2X 1Y4',
                    ],
                ],
            ],
        ]);

        $result = (new JsonLdAddressExtractor())->extract($html);

        $this->assertNotNull($result);
        $this->assertSame('789 Elm Ave', $result->street_address);
        $this->assertSame('Montreal', $result->city);
    }

    public function test_handles_at_type_as_array_with_local_business(): void
    {
        // schema.org allows @type to be an array of types (e.g.
        // ["LocalBusiness","Cafe"]). Confirm we walk it instead of stringifying.
        $html = $this->withJsonLd([
            '@context' => 'https://schema.org',
            '@type' => ['Place', 'CafeOrCoffeeShop', 'LocalBusiness'],
            'name' => 'Multi-type Coffee',
            'address' => [
                'streetAddress' => '321 Pine St',
                'addressLocality' => 'Calgary',
                'addressRegion' => 'AB',
                'postalCode' => 'T2P 1J9',
            ],
        ]);

        $result = (new JsonLdAddressExtractor())->extract($html);

        $this->assertNotNull($result);
        $this->assertSame('321 Pine St', $result->street_address);
    }

    public function test_returns_null_when_no_jsonld_blocks(): void
    {
        $this->assertNull((new JsonLdAddressExtractor())->extract('<html><body>no scripts</body></html>'));
    }

    public function test_returns_null_when_jsonld_present_but_missing_address(): void
    {
        $html = $this->withJsonLd([
            '@type' => 'LocalBusiness',
            'name' => 'No-address Roaster',
        ]);
        $this->assertNull((new JsonLdAddressExtractor())->extract($html));
    }

    public function test_returns_null_when_jsonld_malformed(): void
    {
        $html = '<html><script type="application/ld+json">{not valid json,}</script></html>';
        $this->assertNull((new JsonLdAddressExtractor())->extract($html));
    }

    public function test_returns_null_when_type_is_unrelated(): void
    {
        // A Product schema is NOT a LocalBusiness — skip.
        $html = $this->withJsonLd([
            '@type' => 'Product',
            'name' => 'Yirgacheffe Konga',
            'address' => ['streetAddress' => '999 Wrong Pl', 'addressLocality' => 'X'],
        ]);
        $this->assertNull((new JsonLdAddressExtractor())->extract($html));
    }

    public function test_handles_multiple_jsonld_blocks_and_picks_local_business(): void
    {
        // Many sites have both a WebSite/Organization block AND a separate
        // LocalBusiness block — pick the right one rather than crash on the
        // first non-match.
        $html = '<html>'
            . '<script type="application/ld+json">' . json_encode(['@type' => 'WebSite', 'url' => 'https://x']) . '</script>'
            . '<script type="application/ld+json">' . json_encode([
                '@type' => 'LocalBusiness',
                'name' => 'Picked Coffee',
                'address' => ['streetAddress' => '111 Real St', 'addressLocality' => 'Halifax', 'addressRegion' => 'NS', 'postalCode' => 'B3J 1A1'],
            ]) . '</script>'
            . '</html>';

        $result = (new JsonLdAddressExtractor())->extract($html);

        $this->assertNotNull($result);
        $this->assertSame('111 Real St', $result->street_address);
    }

    private function withJsonLd(array $data): string
    {
        return '<html><head><script type="application/ld+json">'
            . json_encode($data) . '</script></head><body></body></html>';
    }
}
