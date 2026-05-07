<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ShopifyScraper implements RoasterScraper
{
    public function platformKey(): string
    {
        return 'shopify';
    }

    public function canHandle(string $url): bool
    {
        try {
            $endpoint = Shared::origin($url) . '/products.json?limit=1';
            $response = Http::timeout(10)->withOptions(Shared::clientOptions())->acceptJson()->get($endpoint);
            if (!$response->ok()) return false;
            $body = $response->json();
            // Shopify returns {"products":[...]} on success even when empty.
            return is_array($body) && array_key_exists('products', $body);
        } catch (\Throwable) {
            return false;
        }
    }

    public function fetch(string $url): array
    {
        $endpoint = Shared::origin($url) . '/products.json?limit=250';
        $response = Http::timeout(15)->withOptions(Shared::clientOptions())->acceptJson()->get($endpoint);
        if (!$response->ok()) {
            throw new RuntimeException("Shopify fetch failed: {$response->status()} for {$endpoint}");
        }
        return $this->normalize($url, $response->json());
    }

    /** Filter Shopify products to coffee bags and normalize to the RoasterScraper output shape. */
    public function normalize(string $url, ?array $payload): array
    {
        $origin = Shared::origin($url);
        $out = [];

        foreach ($payload['products'] ?? [] as $p) {
            $title = (string) ($p['title'] ?? '');
            $productType = (string) ($p['product_type'] ?? '');
            $tags = is_array($p['tags'] ?? null) ? $p['tags'] : explode(',', (string) ($p['tags'] ?? ''));

            if (!Shared::looksLikeCoffee($title, $productType, $tags)) continue;

            $productUrl = !empty($p['handle'])
                ? $origin . '/products/' . $p['handle']
                : null;

            $rawVariants = [];
            foreach ($p['variants'] ?? [] as $v) {
                $varTitle = (string) ($v['title'] ?? '');
                // Skip multipack / portion-pack / pod / sample-flight variants —
                // they corrupt per-gram pricing because the size we'd record
                // is for one packet, not the bundle's total weight.
                if (Shared::isBadVariantTitle($varTitle)) continue;
                // Try the variant title first; fall back to the product title
                // for shops that put the bag size in the product name and use
                // "Default Title" as the variant (Thom Bargen, Sam James pattern).
                $grams = Shared::parseGrams($varTitle) ?? Shared::parseGrams($title);
                if ($grams === null) continue;
                $variantId = isset($v['id']) ? (string) $v['id'] : null;
                $price = (float) ($v['price'] ?? 0);
                if ($price <= 0) continue;  // free/missing price = bad data
                // Per-variant deep link: Shopify product pages preselect the
                // size dropdown when ?variant=<id> is in the URL.
                $variantPurchaseLink = ($productUrl && $variantId)
                    ? $productUrl . '?variant=' . $variantId
                    : $productUrl;
                $rawVariants[] = [
                    'grams' => $grams,
                    'price' => $price,
                    'available' => (bool) ($v['available'] ?? true),
                    'source_id' => $variantId,
                    'purchase_link' => $variantPurchaseLink,
                    'source_size_label' => Shared::extractSourceSizeLabel($varTitle),
                ];
            }
            $variants = Shared::dedupeVariantsByGrams($rawVariants);
            if (empty($variants)) continue;

            $imageUrl = null;
            if (!empty($p['images']) && is_array($p['images'])) {
                $first = $p['images'][0] ?? null;
                $imageUrl = is_array($first) ? ($first['src'] ?? null) : null;
            }

            $out[] = [
                'name' => $title,
                'source_id' => isset($p['id']) ? (string) $p['id'] : '',
                'description' => strip_tags((string) ($p['body_html'] ?? '')),
                'image_url' => $imageUrl,
                'product_url' => $productUrl,
                'is_blend' => Shared::isBlend($title, $productType, $tags),
                'variants' => $variants,
            ];
        }

        return $out;
    }
}
