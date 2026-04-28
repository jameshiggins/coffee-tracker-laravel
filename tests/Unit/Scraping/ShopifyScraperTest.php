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
