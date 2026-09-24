<?php

namespace App\Services;

use App\Services\Http\SafeHttp;
use App\Services\Scraping\Address\ScrapedAddress;
use Illuminate\Support\Facades\Http;

/**
 * Q12b: turn a free-form street address into lat/lng using OpenStreetMap's
 * Nominatim service. Free, no API key, soft-rate-limit ~1 req/sec.
 *
 * Used by the admin form's "Geocode" button after a human pastes the
 * roaster's address. We don't auto-geocode at scrape time — the
 * about-page scrape doesn't reliably surface street addresses, so admin
 * remains the source of truth.
 *
 * Nominatim's usage policy is a hard max of 1 request/second; exceeding it
 * risks an IP block. throttle() enforces a >=1s gap between any two calls
 * process-wide (no-op under tests so the suite stays fast).
 */
class NominatimGeocoder
{
    public const BASE = 'https://nominatim.openstreetmap.org/search';

    private const MIN_INTERVAL_SECONDS = 1.0;

    private static ?float $lastRequestAt = null;

    /**
     * Why the last call returned null when it was NOT a genuine no-match:
     * an HTTP error status (403 = usage-policy block, 429 = rate limit,
     * 5xx) or a transport exception. Null after a successful call or a
     * real "no results" response. Nominatim blocks are indistinguishable
     * from "no match" without this — the admin Geocode button used to
     * report both identically.
     */
    private ?string $lastError = null;

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Nominatim's usage policy requires a User-Agent that identifies the
     * application AND gives a way to reach the operator; stock library UAs
     * and anonymous ones are refused (403), and datacenter IPs like Fly.io
     * are policed harder than residential ones. The contact email also goes
     * in the `email` query parameter, which the policy asks for so they can
     * reach you before blocking rather than after.
     */
    private function identity(): array
    {
        $appUrl = rtrim((string) config('app.url'), '/');
        $email = trim((string) config('services.nominatim.contact_email'));
        $contact = array_filter([$appUrl !== '' ? '+' . $appUrl : null, $email !== '' ? $email : null]);

        return [
            'user_agent' => 'RoastMap/1.0 (' . implode('; ', $contact ?: ['admin geocoder']) . ')',
            'email' => $email !== '' ? $email : null,
        ];
    }

    private function request(array $query): ?array
    {
        $this->lastError = null;
        $identity = $this->identity();
        if ($identity['email']) {
            $query['email'] = $identity['email'];
        }

        try {
            $this->throttle();
            $response = SafeHttp::client(10)
                ->withHeaders([
                    'User-Agent' => $identity['user_agent'],
                    'Accept-Language' => 'en',
                ])
                ->acceptJson()
                ->get(self::BASE, $query);
        } catch (\Throwable $e) {
            $this->lastError = 'Nominatim request failed: ' . $e->getMessage();
            return null;
        }

        if (!$response->ok()) {
            $body = trim(preg_replace('/\s+/', ' ', strip_tags($response->body())));
            $this->lastError = 'Nominatim returned HTTP ' . $response->status()
                . ($body !== '' ? ': ' . mb_substr($body, 0, 200) : '');
            return null;
        }

        $hits = $response->json();
        return is_array($hits) ? $hits : [];
    }

    /** Block until at least MIN_INTERVAL_SECONDS has passed since the last call. */
    private function throttle(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        if (self::$lastRequestAt !== null) {
            $wait = self::MIN_INTERVAL_SECONDS - (microtime(true) - self::$lastRequestAt);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
        }
        self::$lastRequestAt = microtime(true);
    }

    /**
     * Geocode "{street}, {city}, {region}, {country}" → ['lat'=>..., 'lng'=>...].
     * Returns null on a no-match or transport error; never throws.
     */
    public function geocode(string $streetAddress, ?string $city = null, ?string $region = null, ?string $country = null): ?array
    {
        $query = trim(implode(', ', array_filter([$streetAddress, $city, $region, $country])));
        if ($query === '') return null;

        $hits = $this->request([
            'q' => $query,
            'format' => 'json',
            'limit' => 1,
            'addressdetails' => 0,
        ]);
        if ($hits === null || empty($hits[0])) return null;

        return [
            'lat' => (float) $hits[0]['lat'],
            'lng' => (float) $hits[0]['lon'],
            'display_name' => $hits[0]['display_name'] ?? null,
        ];
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

        $hits = $this->request([
            'q' => $query,
            'format' => 'json',
            'limit' => 1,
            'addressdetails' => 1,
            'countrycodes' => 'ca',
        ]);
        if ($hits === null || empty($hits[0])) return null;

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
    }
}
