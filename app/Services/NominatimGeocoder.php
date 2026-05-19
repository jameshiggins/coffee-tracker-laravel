<?php

namespace App\Services;

use App\Services\Scraping\Address\ScrapedAddress;
use App\Services\Scraping\Shared;
use Illuminate\Support\Facades\Http;

/**
 * Q12b: turn a free-form street address into lat/lng using OpenStreetMap's
 * Nominatim service. Free, no API key, soft-rate-limit ~1 req/sec.
 *
 * Used by the admin form's "Geocode" button after a human pastes the
 * roaster's address. We don't auto-geocode at scrape time — the
 * about-page scrape doesn't reliably surface street addresses, so admin
 * remains the source of truth.
 */
class NominatimGeocoder
{
    public const BASE = 'https://nominatim.openstreetmap.org/search';

    /**
     * Geocode "{street}, {city}, {region}, {country}" → ['lat'=>..., 'lng'=>...].
     * Returns null on a no-match or transport error; never throws.
     */
    public function geocode(string $streetAddress, ?string $city = null, ?string $region = null, ?string $country = null): ?array
    {
        $query = trim(implode(', ', array_filter([$streetAddress, $city, $region, $country])));
        if ($query === '') return null;

        try {
            $response = Http::timeout(10)
                ->withOptions(Shared::clientOptions())
                ->withHeaders([
                    'User-Agent' => 'SpecialtyCoffeeRoasters/1.0 admin geocoder (contact: directory)',
                    'Accept-Language' => 'en',
                ])
                ->acceptJson()
                ->get(self::BASE, [
                    'q' => $query,
                    'format' => 'json',
                    'limit' => 1,
                    'addressdetails' => 0,
                ]);
            if (!$response->ok()) return null;
            $hits = $response->json();
            if (!is_array($hits) || empty($hits[0])) return null;
            return [
                'lat' => (float) $hits[0]['lat'],
                'lng' => (float) $hits[0]['lon'],
                'display_name' => $hits[0]['display_name'] ?? null,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Q-AR step 3: search Nominatim by roaster NAME plus city + country.
     * Returns a ScrapedAddress(source='osm') on success — including lat/lng
     * so the cascade can skip a separate geocode round-trip.
     *
     * Distinct from geocode() which takes a free-form street string. Here we
     * trust OSM's `addressdetails=1` payload to give us structured fields.
     * Rejects results without a house number (city-level rows give us nothing
     * the seeder hasn't already supplied).
     */
    public function searchByName(string $name, ?string $city = null, ?string $country = 'Canada'): ?ScrapedAddress
    {
        $query = trim(implode(', ', array_filter([$name, $city, $country])));
        if ($query === '') return null;

        try {
            $response = Http::timeout(10)
                ->withOptions(Shared::clientOptions())
                ->withHeaders([
                    'User-Agent' => 'SpecialtyCoffeeRoasters/1.0 admin geocoder (contact: directory)',
                    'Accept-Language' => 'en',
                ])
                ->acceptJson()
                ->get(self::BASE, [
                    'q' => $query,
                    'format' => 'json',
                    'limit' => 1,
                    'addressdetails' => 1,
                    'countrycodes' => 'ca',
                ]);
            if (!$response->ok()) return null;
            $hits = $response->json();
            if (!is_array($hits) || empty($hits[0])) return null;
            $hit = $hits[0];
            $addr = $hit['address'] ?? [];
            $houseNumber = $addr['house_number'] ?? null;
            $road = $addr['road'] ?? null;
            // Without a house number AND road we don't have a precise pin —
            // bail out and let the cascade fall through to Google Places.
            if (!$houseNumber || !$road) return null;
            $street = trim("$houseNumber $road");

            return new ScrapedAddress(
                source: 'osm',
                street_address: $street,
                postal_code: $addr['postcode'] ?? null,
                city: $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? null,
                region: $addr['state'] ?? null,
                latitude: isset($hit['lat']) ? (float) $hit['lat'] : null,
                longitude: isset($hit['lon']) ? (float) $hit['lon'] : null,
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
