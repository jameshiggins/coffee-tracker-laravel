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
    public static function extractSingleOrigins(array $payload): array
    {
        $out = [];
        foreach ($payload['products'] ?? [] as $p) {
            if (!self::looksLikeSingleOrigin($p)) continue;

            $variants = [];
            foreach ($p['variants'] ?? [] as $v) {
                $grams = self::parseGrams($v['title'] ?? '');
                if ($grams === null) continue;
                $variants[] = [
                    'id' => $v['id'] ?? null,
                    'title' => $v['title'] ?? null,
                    'grams' => $grams,
                    'price' => (float) ($v['price'] ?? 0),
                    'available' => (bool) ($v['available'] ?? true),
                    'is_default' => false,
                ];
            }
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
                'variants' => $variants,
            ];
        }
        return $out;
    }

    /** Fetch and parse a Shopify storefront's product list. Returns extracted single-origins. */
    public static function fetch(string $url): array
    {
        $endpoint = self::productsUrl($url);
        $response = Http::timeout(15)->acceptJson()->get($endpoint);
        if (!$response->ok()) {
            throw new RuntimeException("Shopify fetch failed: {$response->status()} for {$endpoint}");
        }
        return self::extractSingleOrigins($response->json());
    }

    private static function looksLikeSingleOrigin(array $product): bool
    {
        $type = strtolower($product['product_type'] ?? '');
        $title = strtolower($product['title'] ?? '');
        $tags = array_map('strtolower', is_array($product['tags'] ?? null)
            ? $product['tags']
            : explode(',', (string) ($product['tags'] ?? '')));
        $tagStr = implode(' ', $tags);

        // Hard exclusions: anything that's not coffee.
        $excludeTypes = ['equipment', 'gear', 'merch', 'merchandise', 'gift card', 'subscription', 'apparel'];
        foreach ($excludeTypes as $bad) {
            if (str_contains($type, $bad)) return false;
        }
        if (str_contains($title, 'gift card') || str_contains($title, 'subscription')) return false;
        if (str_contains($tagStr, 'blend')) return false;
        if (str_contains($title, 'blend')) return false;
        if (str_contains($title, 'decaf')) return false;

        // Must look like coffee. If type isn't set we still allow it through if title hints at coffee.
        if ($type === '') return true;
        return str_contains($type, 'coffee') || str_contains($type, 'bean');
    }
}
