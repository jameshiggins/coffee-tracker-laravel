<?php

namespace Tests\Unit\Scraping;

use App\Services\Scraping\GenericHtmlScraper;
use PHPUnit\Framework\TestCase;

/**
 * The last-resort scraper's JSON-LD extraction. Every other platform
 * scraper has dedicated unit coverage; this one was only exercised
 * incidentally through the import self-heal feature test.
 */
class GenericHtmlScraperTest extends TestCase
{
    private const ORIGIN = 'https://roaster.test';

    private function scraper(): GenericHtmlScraper
    {
        return new GenericHtmlScraper();
    }

    private function ldJsonPage(array|string $payload): string
    {
        $json = is_string($payload) ? $payload : json_encode($payload);

        return "<html><head><script type=\"application/ld+json\">{$json}</script></head><body></body></html>";
    }

    public function test_platform_key_and_fallback_can_handle(): void
    {
        $this->assertSame('generic', $this->scraper()->platformKey());
        $this->assertTrue($this->scraper()->canHandle('https://anything.example'));
    }

    public function test_extracts_a_single_schema_product(): void
    {
        $html = $this->ldJsonPage([
            '@type' => 'Product',
            'name' => 'Ethiopia Yirgacheffe 250g',
            'description' => 'Floral and sweet.',
            'url' => '/products/yirgacheffe',
            'image' => 'https://cdn.test/yirg.jpg',
            'offers' => [
                '@type' => 'Offer',
                'price' => '24.50',
                'availability' => 'https://schema.org/InStock',
            ],
        ]);

        $products = $this->scraper()->extractProductsFromHtml($html, self::ORIGIN);

        $this->assertCount(1, $products);
        $p = $products[0];
        $this->assertSame('Ethiopia Yirgacheffe 250g', $p['name']);
        $this->assertSame('https://roaster.test/products/yirgacheffe', $p['product_url']);
        $this->assertSame($p['product_url'], $p['source_id']);
        $this->assertSame('https://cdn.test/yirg.jpg', $p['image_url']);
        $this->assertSame([['grams' => 250, 'price' => 24.5, 'available' => true, 'source_id' => null]], $p['variants']);
    }

    public function test_reads_products_out_of_a_graph_and_ignores_other_types(): void
    {
        $html = $this->ldJsonPage([
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'WebSite', 'name' => 'Roaster'],
                [
                    '@type' => 'Product',
                    'name' => 'Colombia Huila 340g',
                    'offers' => ['@type' => 'Offer', 'price' => '21.00'],
                ],
            ],
        ]);

        $products = $this->scraper()->extractProductsFromHtml($html, self::ORIGIN);

        $this->assertCount(1, $products);
        $this->assertSame('Colombia Huila 340g', $products[0]['name']);
        $this->assertSame(340, $products[0]['variants'][0]['grams']);
    }

    public function test_out_of_stock_availability_marks_variant_unavailable(): void
    {
        $html = $this->ldJsonPage([
            '@type' => 'Product',
            'name' => 'Kenya AA 250g',
            'offers' => [
                '@type' => 'Offer',
                'price' => '26.00',
                'availability' => 'https://schema.org/OutOfStock',
            ],
        ]);

        $products = $this->scraper()->extractProductsFromHtml($html, self::ORIGIN);

        $this->assertFalse($products[0]['variants'][0]['available']);
    }

    public function test_aggregate_offer_low_price_is_used(): void
    {
        $html = $this->ldJsonPage([
            '@type' => 'Product',
            'name' => 'Brazil Santos 1kg',
            'offers' => ['@type' => 'AggregateOffer', 'lowPrice' => '38.00', 'highPrice' => '42.00'],
        ]);

        $products = $this->scraper()->extractProductsFromHtml($html, self::ORIGIN);

        $this->assertSame(38.0, $products[0]['variants'][0]['price']);
    }

    public function test_products_without_parseable_grams_or_price_are_skipped(): void
    {
        $noGrams = $this->ldJsonPage([
            '@type' => 'Product',
            'name' => 'Ethiopia Yirgacheffe',
            'offers' => ['@type' => 'Offer', 'price' => '24.00'],
        ]);
        $noPrice = $this->ldJsonPage([
            '@type' => 'Product',
            'name' => 'Ethiopia Yirgacheffe 250g',
        ]);

        $this->assertSame([], $this->scraper()->extractProductsFromHtml($noGrams, self::ORIGIN));
        $this->assertSame([], $this->scraper()->extractProductsFromHtml($noPrice, self::ORIGIN));
    }

    public function test_non_coffee_products_are_rejected(): void
    {
        $html = $this->ldJsonPage([
            '@type' => 'Product',
            'name' => 'Ceramic Mug 250g',
            'offers' => ['@type' => 'Offer', 'price' => '18.00'],
        ]);

        $this->assertSame([], $this->scraper()->extractProductsFromHtml($html, self::ORIGIN));
    }

    public function test_malformed_json_and_pages_without_ldjson_yield_nothing(): void
    {
        $this->assertSame([], $this->scraper()->extractProductsFromHtml(
            $this->ldJsonPage('{not valid json'), self::ORIGIN
        ));
        $this->assertSame([], $this->scraper()->extractProductsFromHtml(
            '<html><body><h1>No structured data here</h1></body></html>', self::ORIGIN
        ));
    }
}
