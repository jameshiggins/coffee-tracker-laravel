<?php

namespace App\Services;

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
}
