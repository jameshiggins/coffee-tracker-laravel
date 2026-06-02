<?php

namespace Tests\Unit\Scraping;

use App\Services\Scraping\SquarespaceScraper;
use PHPUnit\Framework\TestCase;

class SquarespaceScraperTest extends TestCase
{
    private function scraper(): SquarespaceScraper
    {
        return new SquarespaceScraper();
    }

    public function test_platform_key(): void
    {
        $this->assertSame('squarespace', $this->scraper()->platformKey());
    }

    /**
     * The Prototype case: single-size coffees whose variant carries NO size
     * attribute (`attributes: []`) — the weight lives only in the excerpt
     * ("100g. Tasting Notes: …"). Before the description-grams fallback these
     * dropped (no parseable grams), so the roaster showed zero coffees.
     */
    public function test_attribute_less_variant_recovers_grams_from_excerpt(): void
    {
        $items = [
            [
                'id' => 'p1',
                'title' => 'Bohemia (Washed Gesha), Colombia',
                'fullUrl' => '/shop/bohemia',
                'assetUrl' => 'https://cdn.example.com/bohemia.jpg',
                'excerpt' => '<p>100g. Tasting Notes: Earl Grey, Green Apple Candy, Strawberry Lemonade.</p>',
                'tags' => [],
                'categories' => ['Top Tier'],
                'structuredContent' => [
                    'productType' => 1,
                    'variants' => [
                        [
                            'id' => 'v1',
                            'attributes' => [],
                            'priceMoney' => ['currency' => 'CAD', 'value' => '29.00'],
                            'unlimited' => true,
                            'qtyInStock' => 0,
                        ],
                    ],
                ],
            ],
            [
                'id' => 'p2',
                'title' => 'Gatugi, Kenya',
                'fullUrl' => '/shop/gatugi',
                'excerpt' => '<p>250g. Tasting Notes: Guava, Black Currant.</p>',
                'tags' => [],
                'categories' => ['Top Tier'],
                'structuredContent' => [
                    'productType' => 1,
                    'variants' => [
                        [
                            'id' => 'v2',
                            'attributes' => [],
                            'priceMoney' => ['currency' => 'CAD', 'value' => '31.00'],
                            'unlimited' => true,
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->scraper()->normalize('https://www.prototypecoffee.ca', $items);

        $byName = array_column($result, null, 'name');
        $this->assertArrayHasKey('Bohemia (Washed Gesha), Colombia', $byName, 'origin-named coffee with attribute-less variant must import');
        $this->assertArrayHasKey('Gatugi, Kenya', $byName);
        $this->assertSame(100, $byName['Bohemia (Washed Gesha), Colombia']['variants'][0]['grams']);
        $this->assertSame(29.0, $byName['Bohemia (Washed Gesha), Colombia']['variants'][0]['price']);
        $this->assertSame(250, $byName['Gatugi, Kenya']['variants'][0]['grams']);
    }

    /**
     * The excerpt fallback is LAST-resort: a real size attribute always wins,
     * even when the excerpt happens to mention a different (incidental) size.
     */
    public function test_size_attribute_takes_precedence_over_excerpt_grams(): void
    {
        $items = [
            [
                'id' => 'p1',
                'title' => 'Multi Size Coffee',
                'fullUrl' => '/shop/multi',
                // Excerpt mentions 100g, but the variants are explicitly 250g/1kg.
                'excerpt' => '<p>Try our 100g sampler size too! Tasting Notes: Cocoa.</p>',
                'tags' => [],
                'categories' => ['Single Origin'],
                'structuredContent' => [
                    'productType' => 1,
                    'variants' => [
                        ['id' => 'v1', 'attributes' => ['Size' => '250g'], 'priceMoney' => ['value' => '25.00'], 'unlimited' => true],
                        ['id' => 'v2', 'attributes' => ['Size' => '1kg'], 'priceMoney' => ['value' => '80.00'], 'unlimited' => true],
                    ],
                ],
            ],
        ];

        $result = $this->scraper()->normalize('https://example.com', $items);

        $this->assertCount(1, $result);
        $grams = array_column($result[0]['variants'], 'grams');
        sort($grams);
        $this->assertSame([250, 1000], $grams, 'explicit size attributes win over the excerpt fallback');
    }

    public function test_non_coffee_products_still_dropped(): void
    {
        $items = [
            [
                'id' => 'tote',
                'title' => 'Coffee Plant Tote Bag',
                'fullUrl' => '/shop/tote',
                'excerpt' => '<p>Organic cotton tote.</p>',
                'tags' => [],
                'categories' => ['Merch'],
                'structuredContent' => [
                    'productType' => 1,
                    'variants' => [
                        ['id' => 'tv1', 'attributes' => ['Color' => 'Black'], 'priceMoney' => ['value' => '20.00'], 'unlimited' => true],
                    ],
                ],
            ],
            [
                'id' => 'gc',
                'title' => 'Physical Gift Card',
                'fullUrl' => '/shop/gc',
                'excerpt' => '<p>250g? no — a gift card.</p>',
                'tags' => [],
                'categories' => [],
                // productType 3 = Service → dropped before anything else.
                'structuredContent' => [
                    'productType' => 3,
                    'variants' => [
                        ['id' => 'gv1', 'attributes' => ['Balance' => '$25'], 'priceMoney' => ['value' => '25.00'], 'unlimited' => true],
                    ],
                ],
            ],
        ];

        $result = $this->scraper()->normalize('https://example.com', $items);

        $this->assertSame([], $result, 'tote bag + gift card must not import');
    }

    public function test_coffee_without_any_size_signal_is_dropped(): void
    {
        // No size attribute, no grams in title, no standard size in excerpt →
        // we never invent a weight, so the product drops rather than import
        // with a fabricated size.
        $items = [
            [
                'id' => 'p1',
                'title' => 'Mystery Lot, Ethiopia',
                'fullUrl' => '/shop/mystery',
                'excerpt' => '<p>A wonderful washed coffee. Limited release.</p>',
                'tags' => [],
                'categories' => ['Top Tier'],
                'structuredContent' => [
                    'productType' => 1,
                    'variants' => [
                        ['id' => 'v1', 'attributes' => [], 'priceMoney' => ['value' => '22.00'], 'unlimited' => true],
                    ],
                ],
            ],
        ];

        $result = $this->scraper()->normalize('https://example.com', $items);

        $this->assertSame([], $result, 'no size signal anywhere → drop, never fabricate a weight');
    }
}
