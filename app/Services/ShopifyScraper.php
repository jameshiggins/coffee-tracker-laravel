<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ShopifyScraper
{
    /** Build the Shopify products.json URL from any URL on a roaster's site. */
    public static function productsUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if ($host === '') throw new RuntimeException("Invalid URL: {$url}");
        return "{$scheme}://{$host}/products.json?limit=250";
    }

    /**
     * Parse a Shopify variant title into grams.
     * Tries (in order): "Ng" / "N g", "N oz", "N lb", "N kg".
     * Returns null when no recognizable size token is present.
     */
    public static function parseGrams(string $title): ?int
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*kg\b/i', $title, $m)) {
            return (int) round((float) $m[1] * 1000);
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*g\b/i', $title, $m)) {
            return (int) round((float) $m[1]);
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*lb\b/i', $title, $m)) {
            return (int) round((float) $m[1] * 453.592);
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*oz\b/i', $title, $m)) {
            return (int) round((float) $m[1] * 28.3495);
        }
        return null;
    }

    /**
     * Filter Shopify products down to single-origin coffees and normalize variants.
     * Drops blends, gear, gift cards, subscriptions.
     */
    public static function extractSingleOrigins(?array $payload): array
    {
        $out = [];
        foreach ($payload['products'] ?? [] as $p) {
            if (!self::looksLikeSingleOrigin($p)) continue;

            // Dedupe variants by parsed grams. Roasters often list the same bag
            // size in two units ("12oz" and "340g"), which both resolve to 340g
            // and would violate the (coffee_id, bag_weight_grams) unique index.
            // Prefer an available variant over an unavailable one when colliding.
            $byGrams = [];
            foreach ($p['variants'] ?? [] as $v) {
                $grams = self::parseGrams($v['title'] ?? '');
                if ($grams === null) continue;
                $available = (bool) ($v['available'] ?? true);
                $existing = $byGrams[$grams] ?? null;
                if ($existing && $existing['available'] && !$available) continue;
                $byGrams[$grams] = [
                    'id' => $v['id'] ?? null,
                    'title' => $v['title'] ?? null,
                    'grams' => $grams,
                    'price' => (float) ($v['price'] ?? 0),
                    'available' => $available,
                    'is_default' => false,
                ];
            }
            ksort($byGrams);
            $variants = array_values($byGrams);
            if (empty($variants)) continue;

            // First available variant is the default; if none available, first overall.
            $defaultIndex = array_search(true, array_column($variants, 'available'), true);
            if ($defaultIndex === false) $defaultIndex = 0;
            $variants[$defaultIndex]['is_default'] = true;

            $out[] = [
                'name' => $p['title'] ?? '',
                'description' => strip_tags($p['body_html'] ?? ''),
                'tags' => $p['tags'] ?? [],
                'product_type' => $p['product_type'] ?? null,
                'handle' => $p['handle'] ?? null,
                'is_blend' => self::isBlend($p),
                'variants' => $variants,
            ];
        }
        return $out;
    }

    /** Fetch and parse a Shopify storefront's product list. Returns extracted single-origins. */
    public static function fetch(string $url): array
    {
        $endpoint = self::productsUrl($url);
        $response = Http::timeout(15)
            ->withOptions(self::clientOptions())
            ->acceptJson()
            ->get($endpoint);
        if (!$response->ok()) {
            throw new RuntimeException("Shopify fetch failed: {$response->status()} for {$endpoint}");
        }
        return self::extractSingleOrigins($response->json());
    }

    /**
     * Guzzle options for outbound HTTPS — Windows PHP doesn't ship a CA bundle,
     * so point at the Mozilla bundle we keep under storage/. Falls back to system
     * defaults on environments where the file isn't present.
     */
    private static function clientOptions(): array
    {
        $opts = [];
        $cacert = storage_path('cacert.pem');
        if (is_readable($cacert)) {
            $opts['verify'] = $cacert;
        }
        // A real-looking UA helps with the few storefronts that block obvious scripts.
        $opts['headers']['User-Agent'] = 'SpecialtyCoffeeRoasters/1.0 (+contact: directory)';
        return $opts;
    }

    /**
     * Best-effort detection of "is this a blend?" from a Shopify product.
     * Looks for "blend" / "espresso blend" in tags, product_type, or title.
     * False negatives are fine — admin can correct via the form.
     */
    public static function isBlend(array $product): bool
    {
        $tags = is_array($product['tags'] ?? null)
            ? $product['tags']
            : explode(',', (string) ($product['tags'] ?? ''));
        $haystack = strtolower(implode(' | ', [
            $product['title'] ?? '',
            $product['product_type'] ?? '',
            implode(' ', $tags),
        ]));
        return str_contains($haystack, 'blend');
    }

    /**
     * Is this product a coffee bean we want in the directory?
     * Includes single-origins, blends, decaf — anything that's a bag of beans.
     * Excludes gear, gift cards, subscriptions, anything with no coffee signal.
     */
    private static function looksLikeSingleOrigin(array $product): bool
    {
        $type = strtolower($product['product_type'] ?? '');
        $title = strtolower($product['title'] ?? '');

        // Hard exclusions by product_type — anything that's clearly not coffee.
        $excludeTypes = ['equipment', 'gear', 'merch', 'merchandise', 'gift card', 'subscription', 'apparel'];
        foreach ($excludeTypes as $bad) {
            if (str_contains($type, $bad)) return false;
        }
        // Hard exclusions by title for items often tagged as "Coffee" but not actually a bag of beans.
        if (str_contains($title, 'gift card') || str_contains($title, 'subscription')) return false;

        // If product_type isn't set we let it through (some sites don't categorise);
        // otherwise it must positively look like coffee/beans.
        if ($type === '') return true;
        return str_contains($type, 'coffee') || str_contains($type, 'bean');
    }
}
