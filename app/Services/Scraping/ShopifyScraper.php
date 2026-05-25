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

    /**
     * Find a bag-weight signal inside a product description. Strict by
     * design — only accept matches that resolve to a STANDARD specialty-
     * coffee bag size (100, 200, 227, 250, 340, 454, 500, 1000, 2268 g,
     * or the imperial 5lb / 12oz). This rules out incidental numbers in
     * the description ("Altitude: 1600 MASL", "Notes: ... 200 m³ ...")
     * that would otherwise produce wildly wrong sizes.
     *
     * The collapsed-whitespace input handles Botany Rd's "2  50G" and
     * "250  G" template artifacts as "2 50G" / "250 G", which parseGrams
     * then resolves to 250g.
     */
    private function parseBodyGrams(string $body): ?int
    {
        if ($body === '') return null;
        // Standard specialty-coffee bag sizes (Canadian + imperial). The
        // whitelist is what keeps incidental description numbers (altitude,
        // brew recipes, harvest years) from becoming bag weights.
        // 225g = some shops' "8oz" rounding; 227g = canonical 1/2 lb.
        $standard = [100, 200, 225, 227, 250, 300, 340, 454, 500, 1000, 2000, 2268];

        // Pass 1: standard "<digits>[unit]" matches.
        if (preg_match_all('/\b(\d+(?:[.,]\d+)?)\s*(g|gram|grams|kg|kilo|kilos|oz|ounce|ounces|lb|lbs|pound|pounds)\b/iu', $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $candidate = Shared::parseGrams($m[0]);
                if ($candidate !== null && in_array($candidate, $standard, true)) {
                    return $candidate;
                }
            }
        }

        // Pass 2: whitespace-typo recovery for the "2 50G" → 250 pattern
        // seen on Botany Rd (template artifact splits the leading digit
        // from the rest). Only accept when concatenating produces a
        // standard size — without that guard, "30 g" → 30 wouldn't
        // help, and "3 12g" → 312 wouldn't either.
        if (preg_match_all('/\b(\d)\s+(\d+)\s*(g|gram|grams|kg|oz|lb)\b/iu', $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $combined = (int) ($m[1] . $m[2]);
                if (in_array($combined, $standard, true)) {
                    return $combined;
                }
            }
        }

        return null;
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

            // Pre-compute a body_html-derived gram weight for the product —
            // used as a last-resort fallback when neither variant title nor
            // product title carry a parseable bag size. Botany Rd is the
            // canonical case: variants are all "Default Title", product
            // titles read "DORSIA | MILK BAR" / "ZOQUIÁPAM WASHED | MEXICO"
            // with no grams, but the body_html includes "250G". Whitespace
            // is collapsed first because some templates render the size as
            // "2  50G" or "250  G" via formatting artifacts.
            // Replace tags with a space BEFORE stripping, so "</p><p>250G</p>"
            // becomes " 250G " not "Bag250G" (which would have no word boundary
            // before the digit and parseGrams would miss it entirely).
            $bodyForWeight = preg_replace('/\s+/', ' ', strip_tags(
                preg_replace('/<[^>]+>/', ' ', (string) ($p['body_html'] ?? ''))
            ));
            $bodyGrams = $this->parseBodyGrams($bodyForWeight);

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
                // Last resort: the body_html — covers Default-Title-only sites
                // that put the bag size only in the product description (Botany
                // Rd pattern).
                $grams = Shared::parseGrams($varTitle)
                    ?? Shared::parseGrams($title)
                    ?? $bodyGrams;
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
