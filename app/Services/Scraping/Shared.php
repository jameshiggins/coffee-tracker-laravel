<?php

namespace App\Services\Scraping;

/**
 * Cross-platform helpers used by every scraper implementation.
 * Static utility class — no state.
 */
final class Shared
{
    /**
     * Parse a bag-size string ("250g", "12oz", "1lb", "1kg") into grams.
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
     * Guzzle options for outbound HTTPS — Windows PHP doesn't ship a CA bundle,
     * so point at the Mozilla bundle we keep under storage/. Falls back to
     * system defaults on environments where the file isn't present.
     */
    public static function clientOptions(): array
    {
        $opts = [];
        $cacert = storage_path('cacert.pem');
        if (is_readable($cacert)) {
            $opts['verify'] = $cacert;
        }
        $opts['headers']['User-Agent'] = 'SpecialtyCoffeeRoasters/1.0 (+contact: directory)';
        return $opts;
    }

    /** Strip an URL down to scheme + host (no path, query, or fragment). */
    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if ($host === '') {
            throw new \RuntimeException("Invalid URL: {$url}");
        }
        return "{$scheme}://{$host}";
    }

    /**
     * Dedupe variants by parsed grams. Roasters often list the same bag size
     * in two units ("12oz" and "340g") which both resolve to 340g and would
     * violate the (coffee_id, bag_weight_grams) unique index. Prefer an
     * available variant over an unavailable one when colliding.
     *
     * Input: array of variants where each has at least 'grams' and 'available'.
     * Output: same shape, sorted ascending by grams, deduped.
     */
    public static function dedupeVariantsByGrams(array $variants): array
    {
        $byGrams = [];
        foreach ($variants as $v) {
            if (!isset($v['grams'])) continue;
            $g = (int) $v['grams'];
            $existing = $byGrams[$g] ?? null;
            $available = (bool) ($v['available'] ?? true);
            if ($existing && $existing['available'] && !$available) continue;
            $byGrams[$g] = $v;
        }
        ksort($byGrams);
        return array_values($byGrams);
    }

    /**
     * Should this product be imported as a coffee bean?
     * Excludes gear, gift cards, subscriptions, sample packs.
     * Includes single-origins, blends, decaf — anything that's a bag of beans.
     */
    public static function looksLikeCoffee(string $title, string $productType = ''): bool
    {
        $type = strtolower($productType);
        $titleLower = strtolower($title);

        $excludeTypes = ['equipment', 'gear', 'merch', 'merchandise', 'gift card', 'subscription', 'apparel'];
        foreach ($excludeTypes as $bad) {
            if (str_contains($type, $bad)) return false;
        }
        if (str_contains($titleLower, 'gift card') || str_contains($titleLower, 'subscription')) return false;
        if (str_contains($titleLower, 'sample set') || str_contains($titleLower, 'sample pack')) return false;
        if (str_contains($titleLower, 'sample') && (str_contains($titleLower, 'add-on') || str_contains($titleLower, 'add on'))) return false;
        if (preg_match('/\bsample sets?\b/', $titleLower) || str_contains($titleLower, 'tasting set')) return false;

        if ($type === '') return true;
        return str_contains($type, 'coffee') || str_contains($type, 'bean');
    }

    /**
     * Best-effort blend detection. Two signals:
     * 1) Explicit: "blend" appears in title/tags/product_type.
     * 2) Probabilistic: "Espresso" tag without "Single Origin" tag → blend
     *    (espresso products are blends ~90% of the time at specialty roasters).
     */
    public static function isBlend(string $title, string $productType, array $tags): bool
    {
        $tagsLower = array_map(fn ($t) => strtolower(trim((string) $t)), $tags);
        $tagStr = implode(' ', $tagsLower);
        $haystack = strtolower(implode(' | ', [$title, $productType, $tagStr]));

        if (str_contains($haystack, 'blend')) return true;

        $isSingleOrigin = str_contains($tagStr, 'single origin') || str_contains($tagStr, 'single-origin');
        $isEspresso = in_array('espresso', $tagsLower, true) || str_contains($title, 'Espresso');
        return $isEspresso && !$isSingleOrigin;
    }
}
