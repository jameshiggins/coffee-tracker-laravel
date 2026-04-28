<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Last-resort scraper for sites that aren't on Shopify or WooCommerce.
 * Strategy:
 *  1) Fetch the homepage and any /shop / /products / /collections pages we
 *     can find linked from it.
 *  2) On each page, look for application/ld+json blocks containing schema.org
 *     Product objects. Modern Squarespace, Webflow, custom sites usually
 *     embed these. They give us structured name/image/price.
 *  3) Failing that, extract og:product:* meta tags or grab the first
 *     <h1>/og:title and look for a heuristic price-like string.
 *
 * Caveats: results are best-effort. Variants / per-bag-size pricing rarely
 * survive structured-data extraction; most generic-scraped products end up
 * as a single-variant import. The admin form lets a human fill in the rest.
 */
class GenericHtmlScraper implements RoasterScraper
{
    public function platformKey(): string
    {
        return 'generic';
    }

    public function canHandle(string $url): bool
    {
        // Last-resort fallback: always claims to handle. ScraperRegistry tries
        // Shopify and Woo first; we only run when those failed.
        return true;
    }

    public function fetch(string $url): array
    {
        $origin = Shared::origin($url);
        $homepageHtml = $this->fetchHtml($origin);

        $candidatePaths = $this->findShopPaths($homepageHtml);
        $allProducts = $this->extractProductsFromHtml($homepageHtml, $origin);
        foreach ($candidatePaths as $path) {
            try {
                $html = $this->fetchHtml($origin . $path);
                $allProducts = array_merge($allProducts, $this->extractProductsFromHtml($html, $origin));
            } catch (\Throwable) {
                // best-effort; keep going
            }
        }

        // Dedupe by source_id (the schema-product URL we use as identity)
        $seen = [];
        $out = [];
        foreach ($allProducts as $p) {
            $key = $p['source_id'] ?: $p['name'];
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $p;
        }
        return $out;
    }

    private function fetchHtml(string $url): string
    {
        $response = Http::timeout(15)->withOptions(Shared::clientOptions())->get($url);
        if (!$response->ok()) {
            throw new RuntimeException("Generic fetch failed: {$response->status()} for {$url}");
        }
        return $response->body();
    }

    /** Common shop/product collection paths linked from the homepage. */
    private function findShopPaths(string $html): array
    {
        $candidates = ['/shop', '/products', '/collections/all', '/store', '/coffee', '/beans'];
        $found = [];
        foreach ($candidates as $path) {
            if (str_contains($html, $path)) $found[] = $path;
        }
        return array_values(array_unique($found));
    }

    /** Extract Product objects from <script type="application/ld+json"> blocks. */
    public function extractProductsFromHtml(string $html, string $origin): array
    {
        $out = [];
        if (!preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.+?)<\/script>/is', $html, $m)) {
            return [];
        }
        foreach ($m[1] as $jsonRaw) {
            $data = json_decode(trim(html_entity_decode($jsonRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8')), true);
            if (!$data) continue;
            // Could be a single Product, an @graph of mixed types, or an array of Products.
            $candidates = isset($data['@graph']) ? $data['@graph'] : (array_is_list($data) ? $data : [$data]);
            foreach ($candidates as $obj) {
                if (!is_array($obj)) continue;
                $type = $obj['@type'] ?? null;
                $type = is_array($type) ? ($type[0] ?? null) : $type;
                if (strtolower((string) $type) !== 'product') continue;
                $product = $this->productFromSchema($obj, $origin);
                if ($product) $out[] = $product;
            }
        }
        return $out;
    }

    private function productFromSchema(array $obj, string $origin): ?array
    {
        $name = trim((string) ($obj['name'] ?? ''));
        if ($name === '') return null;
        if (!Shared::looksLikeCoffee($name, '')) return null;

        $offers = $obj['offers'] ?? null;
        $price = null;
        $available = true;
        if (is_array($offers)) {
            // Could be a single Offer or AggregateOffer or list of Offers.
            $offerList = isset($offers['@type']) ? [$offers] : (array_is_list($offers) ? $offers : [$offers]);
            foreach ($offerList as $o) {
                if (!is_array($o)) continue;
                if (isset($o['price'])) {
                    $price = (float) $o['price'];
                    $available = (string) ($o['availability'] ?? '') !== 'https://schema.org/OutOfStock';
                    break;
                }
                if (isset($o['lowPrice'])) {
                    $price = (float) $o['lowPrice'];
                    break;
                }
            }
        }

        // Try to parse grams from the name; otherwise default to a single
        // unknown-size variant we can't include (skip).
        $grams = Shared::parseGrams($name);
        if ($grams === null || $price === null) return null;

        $imageUrl = null;
        if (isset($obj['image'])) {
            $img = $obj['image'];
            $imageUrl = is_array($img) ? (string) ($img[0] ?? $img['url'] ?? '') : (string) $img;
            if ($imageUrl === '') $imageUrl = null;
        }

        $productUrl = $obj['url'] ?? $obj['@id'] ?? null;
        if ($productUrl && is_string($productUrl) && !str_starts_with($productUrl, 'http')) {
            $productUrl = $origin . '/' . ltrim($productUrl, '/');
        }

        return [
            'name' => $name,
            'source_id' => $productUrl ?: $name,
            'description' => trim((string) ($obj['description'] ?? '')),
            'image_url' => $imageUrl,
            'product_url' => $productUrl,
            'is_blend' => Shared::isBlend($name, '', []),
            'variants' => [[
                'grams' => $grams,
                'price' => $price,
                'available' => $available,
                'source_id' => null,
            ]],
        ];
    }
}
