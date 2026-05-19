<?php

namespace App\Services\Scraping\Address;

/**
 * Step 4 of the address-resolution cascade — INTENTIONALLY STUBBED.
 *
 * Implementing the real Google Places "Find Place from Text" call requires
 * a Google Cloud project, billing enabled, and an API key restricted to the
 * Places API. To wire it up:
 *
 *   1. Provision a key:
 *        https://console.cloud.google.com/google/maps-apis/credentials
 *   2. Restrict it to the Places API (and ideally HTTP referrer / IP).
 *   3. Add to .env (and Fly.io secrets):
 *        GOOGLE_PLACES_API_KEY=<value>
 *   4. config/services.php already exposes it as
 *        config('services.google_places.key').
 *   5. Replace the TODO body of resolve() with a Http::get() against
 *        https://maps.googleapis.com/maps/api/place/findplacefromtext/json
 *      using fields=formatted_address,geometry,place_id and parse the result
 *      into a ScrapedAddress(source='google'). Cache the place_id on the
 *      roaster row (google_place_id) for future enrichment passes.
 *
 * The cascade in AddressScraper calls hasKey() first and skips this step
 * gracefully when the key isn't configured — so leaving this stub in place
 * costs nothing in production until the key lands.
 */
class GooglePlacesAddressResolver
{
    public function hasKey(): bool
    {
        $key = config('services.google_places.key');
        return is_string($key) && $key !== '';
    }

    /**
     * TODO: real implementation of the Find-Place-from-Text + Place Details
     * flow. Until then, calling this without a key surfaces the misconfig
     * loudly instead of silently returning null.
     */
    public function resolve(string $name, ?string $city = null): ?ScrapedAddress
    {
        if (!$this->hasKey()) {
            throw new \RuntimeException(
                'Google Places step requires GOOGLE_PLACES_API_KEY — see config/services.php'
            );
        }
        // TODO: implement Find-Place-from-Text + Place Details extraction.
        return null;
    }
}
