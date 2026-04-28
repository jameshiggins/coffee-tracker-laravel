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
        return $this->normalize($url, $all);
    }

    public function normalize(string $url, array $products): array
    {
        $out = [];
        foreach ($products as $p) {
            $title = (string) ($p['name'] ?? '');
            $categories = $p['categories'] ?? [];
            $tags = array_map(fn ($c) => is_array($c) ? ($c['name'] ?? '') : (string) $c, $categories);
            $productType = $tags[0] ?? '';

            if (!Shared::looksLikeCoffee($title, $productType)) continue;

            $rawVariants = [];
            $variations = $p['variations'] ?? [];
            if (!empty($variations) && is_array($variations)) {
                // Variations come back as array of per-variation objects (simplified shape)
                foreach ($variations as $v) {
                    $varTitle = '';
                    foreach ($v['attributes'] ?? [] as $a) {
                        $varTitle .= ' ' . (is_array($a) ? ($a['value'] ?? '') : (string) $a);
                    }
                    $grams = Shared::parseGrams($varTitle);
                    if ($grams === null) continue;
                    $rawVariants[] = [
                        'grams' => $grams,
                        'price' => (float) ($v['prices']['price'] ?? 0) / 100, // Woo cents → dollars
                        'available' => (bool) ($v['is_in_stock'] ?? true),
                        'source_id' => isset($v['id']) ? (string) $v['id'] : null,
                    ];
                }
            } else {
                // Simple product: one variant, parse grams from product name.
                $grams = Shared::parseGrams($title);
                if ($grams !== null) {
                    $rawVariants[] = [
                        'grams' => $grams,
                        'price' => (float) ($p['prices']['price'] ?? 0) / 100,
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
                'description' => strip_tags((string) ($p['short_description'] ?? $p['description'] ?? '')),
                'image_url' => $imageUrl,
                'product_url' => $p['permalink'] ?? null,
                'is_blend' => Shared::isBlend($title, $productType, $tags),
                'variants' => $variants,
            ];
        }
        return $out;
    }
}
