<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * WooCommerce Store API: /wp-json/wc/store/products
 * Public endpoint, no auth, returns JSON. Per-page max 100; we paginate.
 */
class WooCommerceScraper implements RoasterScraper
{
    private const PER_PAGE = 100;
    private const MAX_PAGES = 5;

    public function platformKey(): string
    {
        return 'woocommerce';
    }

    public function canHandle(string $url): bool
    {
        try {
            $endpoint = Shared::origin($url) . '/wp-json/wc/store/products?per_page=1';
            $response = Http::timeout(10)->withOptions(Shared::clientOptions())->acceptJson()->get($endpoint);
            if (!$response->ok()) return false;
            $body = $response->json();
            return is_array($body); // top-level array of products on success
        } catch (\Throwable) {
            return false;
        }
    }

    public function fetch(string $url): array
    {
        $origin = Shared::origin($url);
        $all = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $endpoint = $origin . '/wp-json/wc/store/products?per_page=' . self::PER_PAGE . '&page=' . $page;
            $response = Http::timeout(15)->withOptions(Shared::clientOptions())->acceptJson()->get($endpoint);
            if (!$response->ok()) {
                throw new RuntimeException("WooCommerce fetch failed: {$response->status()} for {$endpoint}");
            }
            $batch = $response->json();
            if (!is_array($batch) || empty($batch)) break;
            $all = array_merge($all, $batch);
            if (count($batch) < self::PER_PAGE) break;
        }
        $this->hydrateVariantPrices($origin, $all);
        return $this->normalize($url, $all);
    }

    /**
     * Some WC Store API installs (Oso Negro is the canonical case) return
     * variations as bare {id, attributes} stubs in the bulk listing — no
     * inline price, no in_stock flag. Without per-variant hydration, every
     * variant gets dropped by the `price <= 0` guard in normalize() and
     * the entire product silently vanishes.
     *
     * For each product whose first variant lacks `prices`, fetch the
     * individual variant detail endpoint (one HTTP per variant) and merge
     * the missing `prices` / `is_in_stock` back into the listing. The
     * "normal" WC shape that already inlines variant prices is unchanged.
     */
    private function hydrateVariantPrices(string $origin, array &$products): void
    {
        foreach ($products as $pIdx => $product) {
            $variations = $product['variations'] ?? null;
            if (!is_array($variations) || empty($variations)) continue;
            // Already hydrated by the bulk endpoint? Don't waste round-trips.
            if (isset($variations[0]['prices']['price'])) continue;

            foreach ($variations as $vIdx => $v) {
                $id = $v['id'] ?? null;
                if (!$id) continue;
                try {
                    $resp = Http::timeout(10)
                        ->withOptions(Shared::clientOptions())
                        ->acceptJson()
                        ->get($origin . '/wp-json/wc/store/products/' . $id);
                } catch (\Throwable) {
                    continue;
                }
                if (!$resp->ok()) continue;
                $hydrated = $resp->json();
                if (!is_array($hydrated)) continue;
                // Write back into the outer-array slot directly. Using
                // foreach-by-reference here would write into a COPY of
                // $product['variations'] and silently lose the hydration.
                $products[$pIdx]['variations'][$vIdx]['prices'] = $hydrated['prices'] ?? null;
                $products[$pIdx]['variations'][$vIdx]['is_in_stock'] = $hydrated['is_in_stock'] ?? true;
            }
        }
    }

    public function normalize(string $url, array $products): array
    {
        $out = [];
        foreach ($products as $p) {
            $title = (string) ($p['name'] ?? '');
            $categories = $p['categories'] ?? [];
            $tags = array_map(fn ($c) => is_array($c) ? ($c['name'] ?? '') : (string) $c, $categories);
            $productType = $tags[0] ?? '';

            if (!Shared::looksLikeCoffee($title, $productType, $tags)) continue;

            $rawVariants = [];
            $variations = $p['variations'] ?? [];
            if (!empty($variations) && is_array($variations)) {
                foreach ($variations as $v) {
                    $varTitle = '';
                    foreach ($v['attributes'] ?? [] as $a) {
                        $varTitle .= ' ' . (is_array($a) ? ($a['value'] ?? '') : (string) $a);
                    }
                    $grams = Shared::parseGrams($varTitle);
                    if ($grams === null) continue;
                    $price = (float) ($v['prices']['price'] ?? 0) / 100;
                    if ($price <= 0) continue;  // skip zero/missing prices
                    $rawVariants[] = [
                        'grams' => $grams,
                        'price' => $price,
                        'available' => (bool) ($v['is_in_stock'] ?? true),
                        'source_id' => isset($v['id']) ? (string) $v['id'] : null,
                    ];
                }
            } else {
                $grams = Shared::parseGrams($title);
                $price = (float) ($p['prices']['price'] ?? 0) / 100;
                if ($grams !== null && $price > 0) {
                    $rawVariants[] = [
                        'grams' => $grams,
                        'price' => $price,
                        'available' => (bool) ($p['is_in_stock'] ?? true),
                        'source_id' => null,
                    ];
                }
            }

            $variants = Shared::dedupeVariantsByGrams($rawVariants);
            if (empty($variants)) continue;

            $imageUrl = null;
            if (!empty($p['images'][0]['src'])) {
                $imageUrl = (string) $p['images'][0]['src'];
            }

            $out[] = [
                'name' => $title,
                'source_id' => isset($p['id']) ? (string) $p['id'] : '',
                // Replace tags with space before strip_tags so adjacent
                // block elements don't merge ("</p><p>" → "OriginProcess").
                'description' => strip_tags(preg_replace('/<[^>]+>/', ' ', (string) ($p['short_description'] ?? $p['description'] ?? ''))),
                'image_url' => $imageUrl,
                'product_url' => $p['permalink'] ?? null,
                'is_blend' => Shared::isBlend($title, $productType, $tags),
                'variants' => $variants,
            ];
        }
        return $out;
    }
}
