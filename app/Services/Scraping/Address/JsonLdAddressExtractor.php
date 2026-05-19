<?php

namespace App\Services\Scraping\Address;

/**
 * Step 1 of the address-resolution cascade. Pure HTML-string parser — no HTTP.
 *
 * Walks every <script type="application/ld+json"> block and looks for a
 * LocalBusiness / Cafe / CoffeeShop (or any LocalBusiness subtype) node
 * carrying a PostalAddress. Handles:
 *   - bare top-level objects ({ "@type": "LocalBusiness", … })
 *   - @graph arrays of mixed-type nodes
 *   - top-level arrays (rare but valid)
 *   - @type expressed as an array of strings (multiple types)
 *   - multiple <script> blocks per page (Organization + LocalBusiness)
 *
 * Returns the first match. Returns null on malformed JSON, missing block,
 * missing address, or non-matching @type.
 */
class JsonLdAddressExtractor
{
    /** Schema.org types we treat as a brick-and-mortar coffee operation. */
    private const LOCAL_BUSINESS_TYPES = [
        'localbusiness', 'cafe', 'cafeorcoffeeshop', 'coffeeshop',
        'restaurant', 'foodestablishment', 'store', 'foodservice',
    ];

    public function extract(string $html): ?ScrapedAddress
    {
        if (!preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.+?)<\/script>/is', $html, $m)) {
            return null;
        }
        foreach ($m[1] as $jsonRaw) {
            $decoded = json_decode(trim(html_entity_decode($jsonRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8')), true);
            if (!is_array($decoded)) continue;

            $candidates = $this->candidates($decoded);
            foreach ($candidates as $node) {
                if (!$this->isLocalBusiness($node)) continue;
                $addr = $this->parseAddress($node['address'] ?? null);
                if ($addr) return $addr;
            }
        }
        return null;
    }

    /** Flatten a decoded JSON-LD root into the list of candidate nodes. */
    private function candidates(array $data): array
    {
        if (isset($data['@graph']) && is_array($data['@graph'])) {
            return $data['@graph'];
        }
        if (array_is_list($data)) {
            return $data;
        }
        return [$data];
    }

    private function isLocalBusiness(array $node): bool
    {
        $type = $node['@type'] ?? null;
        if ($type === null) return false;
        $types = is_array($type) ? $type : [$type];
        foreach ($types as $t) {
            if (!is_string($t)) continue;
            if (in_array(strtolower($t), self::LOCAL_BUSINESS_TYPES, true)) {
                return true;
            }
        }
        return false;
    }

    private function parseAddress(mixed $address): ?ScrapedAddress
    {
        if (!is_array($address)) return null;
        $street = $this->cleanString($address['streetAddress'] ?? null);
        $city = $this->cleanString($address['addressLocality'] ?? null);
        $region = $this->cleanString($address['addressRegion'] ?? null);
        $postal = $this->cleanString($address['postalCode'] ?? null);

        // At minimum we need a street address — without it the address is
        // city-level and gives us nothing the seeder hasn't already supplied.
        if ($street === null || $street === '') return null;

        return new ScrapedAddress(
            source: 'jsonld',
            street_address: $street,
            postal_code: $postal,
            city: $city,
            region: $region,
        );
    }

    private function cleanString(mixed $v): ?string
    {
        if (!is_string($v)) return null;
        $v = trim($v);
        return $v === '' ? null : $v;
    }
}
