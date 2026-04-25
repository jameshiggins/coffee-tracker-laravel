<?php

namespace Tests\Unit;

use App\Services\ShopifyScraper;
use PHPUnit\Framework\TestCase;

class ShopifyScraperTest extends TestCase
{
    public function test_parses_grams_from_variant_title_for_gram_units(): void
    {
        $this->assertSame(250, ShopifyScraper::parseGrams('250g'));
        $this->assertSame(340, ShopifyScraper::parseGrams('340 g'));
        $this->assertSame(1000, ShopifyScraper::parseGrams('1000g / Whole Bean'));
    }

    public function test_parses_grams_from_ounces(): void
    {
        $this->assertSame(340, ShopifyScraper::parseGrams('12oz'));
        $this->assertSame(340, ShopifyScraper::parseGrams('12 oz / Ground'));
        $this->assertSame(227, ShopifyScraper::parseGrams('8oz'));
    }

    public function test_parses_grams_from_pounds(): void
    {
        $this->assertSame(454, ShopifyScraper::parseGrams('1lb'));
        $this->assertSame(454, ShopifyScraper::parseGrams('1 lb / Whole Bean'));
        $this->assertSame(907, ShopifyScraper::parseGrams('2lb'));  // 2 * 453.592 = 907.184
        $this->assertSame(2268, ShopifyScraper::parseGrams('5lb'));
    }

    public function test_parses_grams_from_kilograms(): void
    {
        $this->assertSame(1000, ShopifyScraper::parseGrams('1kg'));
        $this->assertSame(2000, ShopifyScraper::parseGrams('2 kg / Whole Bean'));
    }

    public function test_returns_null_when_no_size_in_title(): void
    {
        $this->assertNull(ShopifyScraper::parseGrams('Whole Bean'));
        $this->assertNull(ShopifyScraper::parseGrams('Default Title'));
        $this->assertNull(ShopifyScraper::parseGrams(''));
    }

    public function test_normalizes_url_to_shopify_products_endpoint(): void
    {
        $this->assertSame('https://example.com/products.json?limit=250',
            ShopifyScraper::productsUrl('https://example.com'));
        $this->assertSame('https://example.com/products.json?limit=250',
            ShopifyScraper::productsUrl('https://example.com/'));
        $this->assertSame('https://shop.example.com/products.json?limit=250',
            ShopifyScraper::productsUrl('https://shop.example.com/some/page'));
    }

    public function test_extracts_coffees_including_blends_filtering_only_non_coffee_items(): void
    {
        // Single-origins AND blends are both in scope. Only non-coffee items
        // (gear, gift cards, subscriptions) get dropped.
        $sample = [
            'products' => [
                ['id' => 1, 'title' => 'Ethiopia Yirgacheffe', 'product_type' => 'Coffee',
                 'tags' => ['Single Origin'], 'body_html' => '<p>Floral, citrus.</p>',
                 'variants' => [
                     ['id' => 11, 'title' => '250g', 'price' => '24.00', 'available' => true],
                     ['id' => 12, 'title' => '1lb', 'price' => '38.00', 'available' => true],
                 ]],
                ['id' => 2, 'title' => 'House Blend', 'product_type' => 'Coffee',
                 'tags' => ['Blend'], 'body_html' => '',
                 'variants' => [['id' => 21, 'title' => '340g', 'price' => '20.00', 'available' => true]]],
                ['id' => 3, 'title' => 'V60 Dripper', 'product_type' => 'Equipment',
                 'tags' => [], 'body_html' => '',
                 'variants' => [['id' => 31, 'title' => 'Default Title', 'price' => '40.00', 'available' => true]]],
            ],
        ];

        $result = ShopifyScraper::extractSingleOrigins($sample);

        $this->assertCount(2, $result, 'should keep both single-origin and blend; drop equipment');
        $names = array_column($result, 'name');
        $this->assertContains('Ethiopia Yirgacheffe', $names);
        $this->assertContains('House Blend', $names);
    }

    public function test_detects_blends_via_title_or_tags(): void
    {
        $this->assertTrue(ShopifyScraper::isBlend(['title' => 'House Blend', 'tags' => []]));
        $this->assertTrue(ShopifyScraper::isBlend(['title' => 'Espresso', 'tags' => ['Blend']]));
        $this->assertTrue(ShopifyScraper::isBlend(['title' => 'X', 'product_type' => 'Espresso Blend', 'tags' => []]));
        $this->assertFalse(ShopifyScraper::isBlend(['title' => 'Ethiopia Yirgacheffe', 'tags' => ['Single Origin']]));
        $this->assertFalse(ShopifyScraper::isBlend(['title' => 'Brazil Natural', 'tags' => []]));
    }

    public function test_detects_espresso_products_as_blends_unless_explicitly_single_origin(): void
    {
        // At small roasters, "Espresso" without a Single-Origin tag is overwhelmingly
        // a blend. Audit of Agro Roasters: Nocturnal/Equilibrium/Equinox Espresso
        // are all blends despite never using the word "blend" in title or tags.
        $this->assertTrue(ShopifyScraper::isBlend([
            'title' => 'Nocturnal Espresso', 'tags' => ['Dark', 'Espresso'],
        ]));
        $this->assertTrue(ShopifyScraper::isBlend([
            'title' => 'Equilibrium Espresso', 'tags' => ['Espresso', 'Medium'],
        ]));
        // BUT: Single-origin espresso DOES exist and should not be misclassified.
        $this->assertFalse(ShopifyScraper::isBlend([
            'title' => 'Ethiopia Guji Daannisa Espresso',
            'tags' => ['Espresso', 'Single Origin'],
        ]));
    }

    public function test_filters_out_sample_packs_and_addons(): void
    {
        // Real Agro listings that are NOT actual single bag-of-beans products.
        $sample = [
            'products' => [
                ['id' => 1, 'title' => 'Single Sample Bags (100g) || Add-On',
                 'product_type' => 'Coffee', 'tags' => ['Sample', 'Single Origin'], 'body_html' => '',
                 'variants' => [['id' => 11, 'title' => '100g', 'price' => '5.00', 'available' => true]]],
                ['id' => 2, 'title' => 'Sample Sets', 'product_type' => 'Coffee',
                 'tags' => ['Espresso', 'Gift', 'Sample', 'Single Origin'], 'body_html' => '',
                 'variants' => [['id' => 21, 'title' => '300g', 'price' => '30.00', 'available' => true]]],
                ['id' => 3, 'title' => 'Real Coffee', 'product_type' => 'Coffee', 'tags' => [],
                 'body_html' => '',
                 'variants' => [['id' => 31, 'title' => '340g', 'price' => '24.00', 'available' => true]]],
            ],
        ];
        $result = ShopifyScraper::extractSingleOrigins($sample);
        $names = array_column($result, 'name');
        $this->assertSame(['Real Coffee'], $names);
    }

    public function test_extract_passes_is_blend_through(): void
    {
        $sample = [
            'products' => [
                ['id' => 1, 'title' => 'Yirgacheffe', 'product_type' => 'Coffee', 'tags' => [],
                 'body_html' => '',
                 'variants' => [['id' => 11, 'title' => '250g', 'price' => '20', 'available' => true]]],
                ['id' => 2, 'title' => 'House Blend', 'product_type' => 'Coffee', 'tags' => [],
                 'body_html' => '',
                 'variants' => [['id' => 21, 'title' => '340g', 'price' => '20', 'available' => true]]],
            ],
        ];
        $result = ShopifyScraper::extractSingleOrigins($sample);
        $byName = array_column($result, null, 'name');
        $this->assertFalse($byName['Yirgacheffe']['is_blend']);
        $this->assertTrue($byName['House Blend']['is_blend']);
    }

    public function test_drops_gift_cards_and_subscriptions_even_with_coffee_type(): void
    {
        $sample = [
            'products' => [
                ['id' => 1, 'title' => 'Coffee Gift Card', 'product_type' => 'Coffee', 'tags' => [],
                 'body_html' => '',
                 'variants' => [['id' => 11, 'title' => '250g', 'price' => '50.00', 'available' => true]]],
                ['id' => 2, 'title' => 'Monthly Subscription', 'product_type' => 'Coffee', 'tags' => [],
                 'body_html' => '',
                 'variants' => [['id' => 21, 'title' => '340g', 'price' => '25.00', 'available' => true]]],
            ],
        ];
        $this->assertSame([], ShopifyScraper::extractSingleOrigins($sample));
    }

    public function test_drops_variants_with_unparseable_size(): void
    {
        $sample = [
            'products' => [
                ['id' => 1, 'title' => 'Test', 'product_type' => 'Coffee', 'tags' => [],
                 'body_html' => '',
                 'variants' => [
                     ['id' => 11, 'title' => '250g', 'price' => '20.00', 'available' => true],
                     ['id' => 12, 'title' => 'Default Title', 'price' => '20.00', 'available' => true],
                 ]],
            ],
        ];

        $result = ShopifyScraper::extractSingleOrigins($sample);
        $this->assertCount(1, $result[0]['variants']);
    }

    public function test_dedupes_variants_that_resolve_to_the_same_grams(): void
    {
        // Some roasters list the same bag in two units. Both "12oz" and "340g"
        // parse to 340g and would violate the (coffee_id, bag_weight_grams)
        // unique index if we let both through.
        $sample = [
            'products' => [[
                'id' => 1, 'title' => 'Test', 'product_type' => 'Coffee', 'tags' => [],
                'body_html' => '',
                'variants' => [
                    ['id' => 11, 'title' => '12oz / Whole Bean', 'price' => '24.00', 'available' => true],
                    ['id' => 12, 'title' => '340g / Ground', 'price' => '24.00', 'available' => true],
                    ['id' => 13, 'title' => '5lb', 'price' => '99.00', 'available' => true],
                ],
            ]],
        ];

        $result = ShopifyScraper::extractSingleOrigins($sample);
        $grams = array_column($result[0]['variants'], 'grams');
        $this->assertSame([340, 2268], $grams, 'duplicate grams collapsed; sorted ascending');
    }

    public function test_dedupe_prefers_available_variant_over_unavailable(): void
    {
        $sample = [
            'products' => [[
                'id' => 1, 'title' => 'Test', 'product_type' => 'Coffee', 'tags' => [],
                'body_html' => '',
                'variants' => [
                    ['id' => 11, 'title' => '12oz', 'price' => '24.00', 'available' => false],
                    ['id' => 12, 'title' => '340g', 'price' => '24.00', 'available' => true],
                ],
            ]],
        ];
        $result = ShopifyScraper::extractSingleOrigins($sample);
        $this->assertSame(12, $result[0]['variants'][0]['id']);
        $this->assertTrue($result[0]['variants'][0]['available']);
    }

    public function test_handles_null_payload_gracefully(): void
    {
        $this->assertSame([], ShopifyScraper::extractSingleOrigins(null));
        $this->assertSame([], ShopifyScraper::extractSingleOrigins([]));
    }

    public function test_marks_first_available_variant_as_default(): void
    {
        $sample = [
            'products' => [
                ['id' => 1, 'title' => 'Test', 'product_type' => 'Coffee', 'tags' => [],
                 'body_html' => '',
                 'variants' => [
                     ['id' => 11, 'title' => '250g', 'price' => '20.00', 'available' => false],
                     ['id' => 12, 'title' => '340g', 'price' => '24.00', 'available' => true],
                     ['id' => 13, 'title' => '1lb', 'price' => '30.00', 'available' => true],
                 ]],
            ],
        ];

        $result = ShopifyScraper::extractSingleOrigins($sample);
        $this->assertFalse($result[0]['variants'][0]['is_default']);
        $this->assertTrue($result[0]['variants'][1]['is_default'], '340g is first available');
        $this->assertFalse($result[0]['variants'][2]['is_default']);
    }
}
