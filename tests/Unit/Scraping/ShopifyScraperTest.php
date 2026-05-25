<?php

namespace Tests\Unit\Scraping;

use App\Services\Scraping\ShopifyScraper;
use PHPUnit\Framework\TestCase;

class ShopifyScraperTest extends TestCase
{
    private function scraper(): ShopifyScraper
    {
        return new ShopifyScraper();
    }

    public function test_platform_key(): void
    {
        $this->assertSame('shopify', $this->scraper()->platformKey());
    }

    public function test_normalize_falls_back_to_body_html_grams_for_default_title_variants(): void
    {
        // Real-world: Botany Rd's Shopify feed has product titles like
        // "DORSIA | MILK BAR" with no weight, variants all titled
        // "Default Title", but body_html contains "250G". Without the
        // body_html fallback, 8 of their 9 in-stock coffees got dropped.
        $payload = [
            'products' => [
                [
                    'id' => 100, 'title' => 'DORSIA | MILK BAR', 'product_type' => '',
                    'tags' => [], 'handle' => 'dorsia-milk-bar',
                    'body_html' => '<p>Producer: Jaguara &amp; Mix Bag</p><p>250G</p>',
                    'variants' => [
                        ['id' => 1001, 'title' => 'Default Title', 'price' => '20.00', 'available' => true],
                    ],
                ],
                [
                    // Whitespace artifact: "250  G" should still resolve to
                    // 250g via the inter-digit-unit gap pattern.
                    'id' => 200, 'title' => 'LA SENDA NATURAL | GUATEMALA', 'product_type' => '',
                    'tags' => [], 'handle' => 'la-senda',
                    'body_html' => '<p>Origin: Guatemala</p><p>250  G</p>',
                    'variants' => [
                        ['id' => 2001, 'title' => 'Default Title', 'price' => '30.00', 'available' => true],
                    ],
                ],
                [
                    // Empty body_html → no fallback available → drop.
                    'id' => 300, 'title' => 'NO-INFO COFFEE', 'product_type' => '',
                    'tags' => [], 'handle' => 'no-info',
                    'body_html' => '',
                    'variants' => [
                        ['id' => 3001, 'title' => 'Default Title', 'price' => '25.00', 'available' => true],
                    ],
                ],
            ],
        ];

        $result = $this->scraper()->normalize('https://shop.example', $payload);

        $names = array_column($result, 'name');
        $this->assertContains('DORSIA | MILK BAR', $names, 'body_html "250G" fallback should import');
        $this->assertContains('LA SENDA NATURAL | GUATEMALA', $names, 'whitespace-tolerant "250  G" should resolve to 250g');
        $this->assertNotContains('NO-INFO COFFEE', $names, 'no body_html signal → still skipped, no blind default');

        $dorsia = collect($result)->firstWhere('name', 'DORSIA | MILK BAR');
        $this->assertSame(250, $dorsia['variants'][0]['grams']);
    }

    public function test_body_grams_fallback_recovers_whitespace_typo_pattern_2_50G(): void
    {
        // Real-world: Botany Rd templates render "250G" as "2 50G" due
        // to a font / formatting artifact. NGUISSE / HABTAMU / ALO all
        // hit this. The pass-2 recovery concatenates the leading digit
        // and accepts only when the result is a standard bag size.
        $payload = [
            'products' => [[
                'id' => 1, 'title' => 'NGUISSE NARE BOMBE | ETHIOPIA', 'product_type' => '',
                'tags' => [], 'handle' => 'nguisse',
                'body_html' => '<p>Origin: Ethiopia</p><p>2 50G</p>',
                'variants' => [
                    ['id' => 11, 'title' => 'Default Title', 'price' => '28.00', 'available' => true],
                ],
            ]],
        ];

        $result = $this->scraper()->normalize('https://shop.example', $payload);

        $this->assertCount(1, $result);
        $this->assertSame(250, $result[0]['variants'][0]['grams']);
    }

    public function test_body_grams_fallback_accepts_225g_as_a_standard_size(): void
    {
        // Botany Rd's QUEBRADITAS body has "225 G" — a real bag size
        // some shops use as an 8oz rounding. Must accept.
        $payload = [
            'products' => [[
                'id' => 1, 'title' => 'QUEBRADITAS', 'product_type' => '',
                'tags' => [], 'handle' => 'q',
                'body_html' => '<p>Producer: …</p><p>225 G</p>',
                'variants' => [
                    ['id' => 11, 'title' => 'Default Title', 'price' => '33.00', 'available' => true],
                ],
            ]],
        ];

        $result = $this->scraper()->normalize('https://shop.example', $payload);

        $this->assertCount(1, $result);
        $this->assertSame(225, $result[0]['variants'][0]['grams']);
    }

    public function test_body_grams_fallback_ignores_non_standard_numbers_to_avoid_false_matches(): void
    {
        // Descriptions often mention altitude ("1600 MASL"), brew recipes
        // ("30 g coffee per 500 ml water"), etc. Without the standard-size
        // whitelist these would set absurd bag weights. None are standard
        // bag sizes, so the product must drop instead of importing with
        // a wrong weight.
        $payload = [
            'products' => [[
                'id' => 1, 'title' => 'BAD MATCH | ETHIOPIA', 'product_type' => '',
                'tags' => [], 'handle' => 'bad',
                'body_html' => '<p>Altitude: 1600 MASL</p><p>Brew at 92°C, 30 g coffee per 500 ml water</p>',
                'variants' => [
                    ['id' => 11, 'title' => 'Default Title', 'price' => '22.00', 'available' => true],
                ],
            ]],
        ];

        $result = $this->scraper()->normalize('https://shop.example', $payload);

        $this->assertEmpty($result, 'altitude / brew-recipe numbers must not become bag weights');
    }

    public function test_normalize_extracts_coffees_and_drops_non_coffee(): void
    {
        $payload = [
            'products' => [
                [
                    'id' => 1, 'title' => 'Ethiopia Yirgacheffe', 'product_type' => 'Coffee',
                    'tags' => ['Single Origin'], 'body_html' => '<p>Floral, citrus.</p>',
                    'handle' => 'ethiopia-yirgacheffe',
                    'images' => [['src' => 'https://cdn.example.com/yirg.jpg']],
                    'variants' => [
                        ['id' => 11, 'title' => '250g', 'price' => '24.00', 'available' => true],
                        ['id' => 12, 'title' => '1lb', 'price' => '38.00', 'available' => true],
                    ],
                ],
                [
                    'id' => 2, 'title' => 'House Blend', 'product_type' => 'Coffee',
                    'tags' => ['Blend'], 'body_html' => '',
                    'handle' => 'house-blend',
                    'variants' => [['id' => 21, 'title' => '340g', 'price' => '20.00', 'available' => true]],
                ],
                [
                    'id' => 3, 'title' => 'V60 Dripper', 'product_type' => 'Equipment',
                    'tags' => [], 'body_html' => '',
                    'variants' => [['id' => 31, 'title' => 'Default Title', 'price' => '40.00', 'available' => true]],
                ],
            ],
        ];

        $result = $this->scraper()->normalize('https://example.com', $payload);

        $this->assertCount(2, $result, 'imports both single-origin and blend; drops equipment');
        $names = array_column($result, 'name');
        $this->assertContains('Ethiopia Yirgacheffe', $names);
        $this->assertContains('House Blend', $names);
    }

    public function test_normalize_passes_is_blend_through(): void
    {
        $payload = [
            'products' => [
                ['id' => 1, 'title' => 'Yirg', 'product_type' => 'Coffee', 'tags' => [],
                 'body_html' => '', 'handle' => 'yirg',
                 'variants' => [['id' => 11, 'title' => '250g', 'price' => '20', 'available' => true]]],
                ['id' => 2, 'title' => 'House Blend', 'product_type' => 'Coffee', 'tags' => [],
                 'body_html' => '', 'handle' => 'house-blend',
                 'variants' => [['id' => 21, 'title' => '340g', 'price' => '20', 'available' => true]]],
            ],
        ];
        $result = $this->scraper()->normalize('https://example.com', $payload);
        $byName = array_column($result, null, 'name');
        $this->assertFalse($byName['Yirg']['is_blend']);
        $this->assertTrue($byName['House Blend']['is_blend']);
    }

    public function test_normalize_builds_product_url_from_handle(): void
    {
        $payload = ['products' => [
            ['id' => 1, 'title' => 'Yirg', 'product_type' => 'Coffee', 'tags' => [],
             'body_html' => '', 'handle' => 'yirg',
             'variants' => [['title' => '250g', 'price' => '20', 'available' => true]]],
        ]];
        $result = $this->scraper()->normalize('https://example.com/some/path?q=x', $payload);
        $this->assertSame('https://example.com/products/yirg', $result[0]['product_url']);
    }

    public function test_normalize_extracts_first_image_url(): void
    {
        $payload = ['products' => [
            ['id' => 1, 'title' => 'Yirg', 'product_type' => 'Coffee', 'tags' => [],
             'body_html' => '', 'handle' => 'yirg',
             'images' => [
                 ['src' => 'https://cdn.example.com/yirg-1.jpg'],
                 ['src' => 'https://cdn.example.com/yirg-2.jpg'],
             ],
             'variants' => [['title' => '250g', 'price' => '20', 'available' => true]]],
        ]];
        $result = $this->scraper()->normalize('https://example.com', $payload);
        $this->assertSame('https://cdn.example.com/yirg-1.jpg', $result[0]['image_url']);
    }

    public function test_normalize_includes_source_id_for_stable_matching(): void
    {
        $payload = ['products' => [
            ['id' => 7891234, 'title' => 'Yirg', 'product_type' => 'Coffee', 'tags' => [],
             'body_html' => '', 'handle' => 'yirg',
             'variants' => [['id' => 99, 'title' => '250g', 'price' => '20', 'available' => true]]],
        ]];
        $result = $this->scraper()->normalize('https://example.com', $payload);
        $this->assertSame('7891234', $result[0]['source_id']);
        $this->assertSame('99', $result[0]['variants'][0]['source_id']);
    }

    public function test_normalize_handles_null_payload(): void
    {
        $this->assertSame([], $this->scraper()->normalize('https://example.com', null));
        $this->assertSame([], $this->scraper()->normalize('https://example.com', []));
    }

    public function test_normalize_drops_products_without_parseable_grams(): void
    {
        $payload = ['products' => [
            ['id' => 1, 'title' => 'Yirg', 'product_type' => 'Coffee', 'tags' => [],
             'body_html' => '', 'handle' => 'yirg',
             'variants' => [
                 ['title' => 'Default Title', 'price' => '20', 'available' => true],
             ]],
        ]];
        $result = $this->scraper()->normalize('https://example.com', $payload);
        $this->assertSame([], $result);
    }
}
