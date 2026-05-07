<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Squarespace shop scraper. Squarespace 7.x exposes any page as a JSON
 * dump via the `?format=json-pretty` query parameter. Shop pages return
 * a `collection` plus an `items[]` array, each with structured-content
 * variants ({attributes: {Bag Size: "300g"}, priceMoney: {currency, value}}).
 *
 * Tries a few common shop slugs (Drumroaster lives at /coffees,
 * Foglifter at /shop, etc.). First slug that returns a non-empty
 * items array wins.
 */
class SquarespaceScraper implements RoasterScraper
{
    private const SHOP_PATHS = ['/shop', '/coffees', '/store', '/products', '/coffee', '/shop-all', '/buy'];

    public function platformKey(): string
    {
        return 'squarespace';
    }

    public function canHandle(string $url): bool
    {
        try {
            $origin = Shared::origin($url);
            // Lightweight probe: any Squarespace page exposes ?format=json-pretty.
            $r = Http::timeout(8)->withOptions(Shared::clientOptions())->get($origin . '?format=json-pretty');
            if (!$r->ok()) return false;
            $body = $r->json();
            return is_array($body)
                && isset($body['website']['id'])
                && str_contains($r->header('Content-Type') ?? '', 'application/json');
        } catch (\Throwable) {
            return false;
        }
    }

    public function fetch(string $url): array
    {
        $origin = Shared::origin($url);
        $items = $this->discoverShopItems($origin);
        if ($items === null) {
            // Could not find a shop page; treat as empty rather than error
            // so the importer records 'empty' status not 'error'.
            return [];
        }
        return $this->normalize($origin, $items);
    }

    /** @return array<int, array<string, mixed>>|null */
    private function discoverShopItems(string $origin): ?array
    {
        foreach (self::SHOP_PATHS as $path) {
            try {
                $r = Http::timeout(15)->withOptions(Shared::clientOptions())
                    ->get($origin . $path . '?format=json-pretty');
                if (!$r->ok()) continue;
                if (!str_contains($r->header('Content-Type') ?? '', 'json')) continue;
                $body = $r->json();
                $items = $body['items'] ?? null;
                if (is_array($items) && count($items) > 0) {
                    return $items;
                }
            } catch (\Throwable) {
                continue;
            }
        }
        return null;
    }

    /** @param array<int, array<string, mixed>> $items */
    public function normalize(string $origin, array $items): array
    {
        $out = [];

        foreach ($items as $p) {
            $title = (string) ($p['title'] ?? '');
            $sc = $p['structuredContent'] ?? [];

            // productType=1 → Physical, =2 → Digital, =3 → Service, =5 → Gift Card
            $productType = (int) ($sc['productType'] ?? 0);
            if ($productType !== 1 && $productType !== 0) continue;

            $tags = is_array($p['tags'] ?? null) ? $p['tags'] : [];
            $categories = is_array($p['categories'] ?? null) ? $p['categories'] : [];
            $combinedTags = array_merge($tags, $categories);

            // Subscription products surface as isSubscribable=true; skip.
            if (!empty($sc['isSubscribable']) && empty($sc['variants'])) continue;
            if (!Shared::looksLikeCoffee($title, '', $combinedTags)) continue;

            $rawVariants = [];
            foreach ($sc['variants'] ?? [] as $v) {
                $attrs = $v['attributes'] ?? [];
                $bagSizeStr = '';
                foreach ($attrs as $key => $val) {
                    if (stripos($key, 'size') !== false || stripos($key, 'weight') !== false || stripos($key, 'bag') !== false) {
                        $bagSizeStr = (string) $val;
                        break;
                    }
                }
                // Fallback: combine all attributes into one string and parse
                if ($bagSizeStr === '' && is_array($attrs)) {
                    $bagSizeStr = implode(' ', array_map('strval', $attrs));
                }
                $grams = Shared::parseGrams($bagSizeStr) ?? Shared::parseGrams($title);
                if ($grams === null) continue;

                $price = (float) ($v['priceMoney']['value'] ?? $v['price'] ?? 0);
                if ($price <= 0) continue;

                $available = empty($v['unlimited'])
                    ? (int) ($v['qtyInStock'] ?? 0) > 0
                    : true;

                $rawVariants[] = [
                    'grams' => $grams,
                    'price' => $price,
                    'available' => $available,
                    'source_id' => isset($v['id']) ? (string) $v['id'] : null,
                ];
            }

            $variants = Shared::dedupeVariantsByGrams($rawVariants);
            if (empty($variants)) continue;

            $imageUrl = $p['assetUrl'] ?? null;
            $productUrl = !empty($p['fullUrl']) ? $origin . $p['fullUrl'] : null;
            $description = (string) ($p['excerpt'] ?? '') ?: (string) ($p['body'] ?? '');
            $description = trim(strip_tags($description));

            $out[] = [
                'name' => $title,
                'source_id' => isset($p['id']) ? (string) $p['id'] : '',
                'description' => $description,
                'image_url' => $imageUrl,
                'product_url' => $productUrl,
                'is_blend' => Shared::isBlend($title, '', $combinedTags),
                'variants' => $variants,
            ];
        }

        return $out;
    }
}
