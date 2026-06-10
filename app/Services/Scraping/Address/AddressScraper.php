<?php

namespace App\Services\Scraping\Address;

use App\Models\Roaster;
use App\Services\Http\SafeHttp;
use App\Services\NominatimGeocoder;
use App\Services\Scraping\Shared;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Q-AR: orchestrates the address-resolution cascade for a single roaster.
 *
 * Order of operations (stops at first hit):
 *   1. JSON-LD LocalBusiness/Cafe on the homepage + common contact paths.
 *   2. Free-text HTML scrape of the same contact paths (postal-code anchor).
 *   3. Nominatim text search by "{name}, {city}, Canada".
 *   4. Google Places "Find Place from Text" — STUBBED. Requires
 *      GOOGLE_PLACES_API_KEY in config/services.php; cascade skips this step
 *      when the key isn't configured.
 *   5. If we resolved an address with no lat/lng (steps 1, 2), geocode it
 *      via NominatimGeocoder::geocode(). Skipped when step 3 already
 *      returned lat/lng.
 *   6. Caller (ScrapeRoasterAddresses) sets is_online_only=true when this
 *      method returns null after a full cascade pass.
 *
 * Already-resolved roasters (address_source IS NOT NULL) are skipped unless
 * the caller asks for `force=true` (the --force CLI flag).
 *
 * No HTTP in tests — Http::fake() is the friend. We rate-limit by sleeping
 * between requests; the artisan command additionally paces between roasters.
 */
class AddressScraper
{
    /**
     * Pages most likely to carry a verifiable street address. We try the
     * homepage first because some sites stuff their LocalBusiness JSON-LD on
     * the root and never link a dedicated contact page.
     */
    private const PATH_CANDIDATES = [
        '/',
        '/contact',
        '/contact-us',
        '/pages/contact',
        '/pages/contact-us',
        '/visit',
        '/pages/visit',
        '/visit-us',
        '/cafe',
        '/cafes',
        '/find-us',
        '/locations',
        '/pages/locations',
        '/pages/our-cafes',
    ];

    public function __construct(
        private readonly JsonLdAddressExtractor $jsonLd = new JsonLdAddressExtractor(),
        private readonly ContactPageAddressExtractor $contact = new ContactPageAddressExtractor(),
        private readonly NominatimGeocoder $nominatim = new NominatimGeocoder(),
        private readonly GooglePlacesAddressResolver $google = new GooglePlacesAddressResolver(),
    ) {
    }

    /**
     * Resolve a precise street address for the roaster. Returns null when
     * the cascade exhausts every step without finding one (caller's cue to
     * set is_online_only=true).
     *
     * `$force` re-runs the cascade even on roasters with a non-null
     * address_source — used by `roasters:scrape-addresses --force`.
     */
    public function scrape(Roaster $r, bool $force = false): ?ScrapedAddress
    {
        if (!$force && $r->address_source !== null) {
            return null;
        }
        if (empty($r->website)) {
            return null;
        }

        $origin = Shared::origin($r->website);

        // STEP 1 + 2: fetch each candidate page once and try both extractors.
        // Sharing the HTML body avoids two trips to the same URL.
        foreach (self::PATH_CANDIDATES as $path) {
            $html = $this->fetchHtml($origin . $path);
            if ($html === null) continue;

            $jsonLdHit = $this->jsonLd->extract($html);
            if ($jsonLdHit) {
                return $this->withCoordinates($jsonLdHit, $r);
            }
            $contactHit = $this->contact->extract($html);
            if ($contactHit) {
                return $this->withCoordinates($contactHit, $r);
            }
        }

        // STEP 3: name-based Nominatim search. Already returns lat/lng.
        if ($r->city) {
            $osm = $this->nominatim->searchByName($r->name, $r->city);
            if ($osm) return $osm;
        }

        // STEP 4: Google Places. Stubbed — skipped when no key configured.
        // When you wire up GOOGLE_PLACES_API_KEY (see config/services.php
        // and GooglePlacesAddressResolver), this branch becomes live.
        if ($this->google->hasKey()) {
            try {
                $gp = $this->google->resolve($r->name, $r->city);
                if ($gp) return $this->withCoordinates($gp, $r);
            } catch (\Throwable $e) {
                Log::warning('Google Places resolver failed', ['roaster' => $r->slug, 'error' => $e->getMessage()]);
            }
        }

        return null;
    }

    private function fetchHtml(string $url): ?string
    {
        try {
            $response = SafeHttp::client(10)
                ->get($url);
            if (!$response->ok()) return null;
            return $response->body();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * STEP 5: when a step returned an address but no lat/lng, geocode it.
     * Steps 1 (jsonld) and 2 (website) populate street/postal only; step 3
     * (osm) returns lat/lng directly and bypasses this.
     */
    private function withCoordinates(ScrapedAddress $addr, Roaster $r): ScrapedAddress
    {
        if ($addr->latitude !== null && $addr->longitude !== null) {
            return $addr;
        }
        $hit = $this->nominatim->geocode(
            $addr->street_address ?? '',
            $addr->city ?? $r->city,
            $addr->region ?? $r->region,
            'Canada',
        );
        if (!$hit) return $addr;

        return new ScrapedAddress(
            source: $addr->source,
            street_address: $addr->street_address,
            postal_code: $addr->postal_code,
            city: $addr->city,
            region: $addr->region,
            latitude: $hit['lat'] ?? null,
            longitude: $hit['lng'] ?? null,
        );
    }
}
