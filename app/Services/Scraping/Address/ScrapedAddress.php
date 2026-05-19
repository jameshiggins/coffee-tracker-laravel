<?php

namespace App\Services\Scraping\Address;

/**
 * Immutable result of one address-resolution cascade step.
 *
 * `source` is the enum-style label persisted to roasters.address_source:
 *   'jsonld' | 'website' | 'osm' | 'google'  (plus 'manual' for hand-entered).
 *
 * Steps 1/2 (jsonld/website) populate street/postal/city/region only and
 * leave lat/lng null — the cascade then geocodes them via Nominatim.
 * Step 3 (osm) returns lat/lng directly so re-geocoding is skipped.
 */
final class ScrapedAddress
{
    public function __construct(
        public readonly string $source,
        public readonly ?string $street_address = null,
        public readonly ?string $postal_code = null,
        public readonly ?string $city = null,
        public readonly ?string $region = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
    ) {
    }
}
