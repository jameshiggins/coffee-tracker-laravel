<?php

namespace Tests\Feature\Scraping;

use App\Services\Scraping\WooCommerceScraper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WooCommerce Store API has TWO endpoints we care about:
 *   /wp-json/wc/store/products           — bulk product listing
 *   /wp-json/wc/store/products/{id}      — single product / variant detail
 *
 * On a chunk of WC installs (Oso Negro is the canonical case), the bulk
 * listing returns variations as bare {id, attributes} stubs — no prices,
 * no in_stock flag. Without per-variant hydration we'd see "variants
 * count: 2" with all variant prices = 0 and drop the product entirely,
 * which silently rejected ~15 of Oso Negro's 17 coffees and left them
 * unimportable until this fix landed.
 */
class WooCommerceScraperTest extends TestCase
{
    private function scraper(): WooCommerceScraper
    {
        return new WooCommerceScraper();
    }

    /** A bulk-listing product whose variants are bare {id, attributes} stubs (Oso Negro shape). */
    private function unhydratedCoffeeProduct(int $id, string $name, array $tags): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'permalink' => "https://shop.example.com/product/{$id}",
            'short_description' => 'A coffee.',
            'description' => '',
            'images' => [['src' => "https://img.example.com/{$id}.jpg"]],
            'categories' => array_map(fn ($t) => ['name' => $t], $tags),
            // Parent price exists (price range), but variants are stubs.
            'prices' => [
                'price' => '1900', 'regular_price' => '1900', 'sale_price' => '1900',
                'price_range' => ['min_amount' => '1900', 'max_amount' => '12867'],
                'currency_minor_unit' => 2,
            ],
            'is_in_stock' => true,
            'variations' => [
                ['id' => $id * 10 + 1, 'attributes' => [['name' => 'Weight', 'value' => '12oz-bag']]],
                ['id' => $id * 10 + 2, 'attributes' => [['name' => 'Weight', 'value' => '5lb-bulk-bag']]],
            ],
        ];
    }

    private function variantDetailResponse(int $id, int $parentId, int $priceCents, bool $inStock = true): array
    {
        return [
            'id' => $id,
            'parent' => $parentId,
            'type' => 'variation',
            'prices' => [
                'price' => (string) $priceCents,
                'regular_price' => (string) $priceCents,
                'sale_price' => (string) $priceCents,
                'currency_minor_unit' => 2,
            ],
            'is_in_stock' => $inStock,
            'attributes' => [],
        ];
    }

    public function test_fetch_hydrates_variant_prices_when_bulk_listing_returns_stubs(): void
    {
        // Two coffees, four variants total. Bulk endpoint returns the
        // unhydrated stubs; each variant detail endpoint returns the
        // missing price + in_stock.
        $campfire = $this->unhydratedCoffeeProduct(100, 'Campfire', ['Africa', 'Americas', 'Dark', 'Indonesia', 'Medium']);
        $meteor = $this->unhydratedCoffeeProduct(200, 'Meteor', ['Americas', 'Dark', 'Indonesia']);

        Http::fake([
            'shop.example.com/wp-json/wc/store/products/1001*' =>
                Http::response($this->variantDetailResponse(1001, 100, 1900), 200),
            'shop.example.com/wp-json/wc/store/products/1002*' =>
                Http::response($this->variantDetailResponse(1002, 100, 12867), 200),
            'shop.example.com/wp-json/wc/store/products/2001*' =>
                Http::response($this->variantDetailResponse(2001, 200, 1900), 200),
            'shop.example.com/wp-json/wc/store/products/2002*' =>
                Http::response($this->variantDetailResponse(2002, 200, 12867), 200),
            // The bulk-listing URL is just /products (no /id suffix). Page 1
            // returns the two products; subsequent pages return empty so the
            // loop terminates.
            'shop.example.com/wp-json/wc/store/products*page=1*' =>
                Http::response([$campfire, $meteor], 200),
            'shop.example.com/wp-json/wc/store/products*' =>
                Http::response([], 200),
        ]);

        $result = $this->scraper()->fetch('https://shop.example.com');

        $this->assertCount(2, $result, 'both Oso-Negro-style coffees should be imported after hydration');
        $names = array_column($result, 'name');
        $this->assertContains('Campfire', $names);
        $this->assertContains('Meteor', $names);

        $campfireOut = collect($result)->firstWhere('name', 'Campfire');
        $this->assertCount(2, $campfireOut['variants'], '12oz + 5lb variants survive');
        $grams = array_column($campfireOut['variants'], 'grams');
        sort($grams);
        $this->assertSame([340, 2268], $grams, '12oz=340g, 5lb=2268g');
        $prices = array_column($campfireOut['variants'], 'price');
        $this->assertEqualsCanonicalizing([19.00, 128.67], $prices);
    }

    public function test_fetch_keeps_inline_prices_without_redundant_per_variant_fetches(): void
    {
        // When the bulk listing already includes prices (the "normal"
        // WC Store API shape), don't waste a round-trip per variant.
        $hydratedProduct = [
            'id' => 1, 'name' => 'House Blend',
            'permalink' => 'https://shop.example.com/product/1',
            'short_description' => '', 'description' => '',
            'images' => [], 'categories' => [['name' => 'Coffee']],
            'is_in_stock' => true,
            'prices' => ['price' => '2200', 'currency_minor_unit' => 2],
            'variations' => [
                [
                    'id' => 11, 'attributes' => [['name' => 'Weight', 'value' => '340g']],
                    'prices' => ['price' => '2200', 'currency_minor_unit' => 2],
                    'is_in_stock' => true,
                ],
            ],
        ];

        Http::fake([
            'shop.example.com/wp-json/wc/store/products?per_page=100&page=1' =>
                Http::response([$hydratedProduct], 200),
            // ANY per-variant fetch would be a regression — the test
            // doesn't stub /products/11, so a fetch there would either
            // throw (no fake) or return the catch-all empty array,
            // either way breaking the assertion below.
            'shop.example.com/wp-json/wc/store/products?per_page=100&page=*' =>
                Http::response([], 200),
            'shop.example.com/wp-json/wc/store/products/*' =>
                Http::response(['ERROR' => 'should not be fetched'], 500),
        ]);

        $result = $this->scraper()->fetch('https://shop.example.com');

        $this->assertCount(1, $result);
        $this->assertSame('House Blend', $result[0]['name']);
        $this->assertSame(22.00, $result[0]['variants'][0]['price']);
    }
}
