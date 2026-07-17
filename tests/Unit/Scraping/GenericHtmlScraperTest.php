<?php

namespace Tests\Unit\Scraping;

use App\Services\Scraping\GenericHtmlScraper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The JSON-LD fallback scraper had zero tests (2026-07 review). These pin its
 * Product extraction from ld+json — including the two parsing defects the
 * review found: sold-out offers read as in-stock, and currency-symbol prices
 * cast to 0 (then dropped by the import sanity gate).
 */
class GenericHtmlScraperTest extends TestCase
{
    private GenericHtmlScraper $scraper;
    private string $origin = 'https://roaster.test';

    protected function setUp(): void
    {
        $this->scraper = new GenericHtmlScraper();
    }

    /** Wrap one or more schema objects in an ld+json <script> block. */
    private function html(array $schema): string
    {
        return '<html><head><script type="application/ld+json">'
            . json_encode($schema)
            . '</script></head><body></body></html>';
    }

    private function product(array $overrides = []): array
    {
        return array_merge([
            '@type' => 'Product',
            'name' => 'Ethiopia Yirgacheffe 250g',
            'offers' => ['@type' => 'Offer', 'price' => '24.00', 'availability' => 'https://schema.org/InStock'],
        ], $overrides);
    }

    private function extract(array $schema): array
    {
        return $this->scraper->extractProductsFromHtml($this->html($schema), $this->origin);
    }

    public function test_extracts_a_single_in_stock_product(): void
    {
        $out = $this->extract($this->product());

        $this->assertCount(1, $out);
        $this->assertSame('Ethiopia Yirgacheffe 250g', $out[0]['name']);
        $this->assertSame(250, $out[0]['variants'][0]['grams']);
        $this->assertSame(24.0, $out[0]['variants'][0]['price']);
        $this->assertTrue($out[0]['variants'][0]['available']);
    }

    /** @return array<string, array{0:string}> */
    public static function soldOutAvailabilityCases(): array
    {
        return [
            'https OutOfStock' => ['https://schema.org/OutOfStock'],
            'http OutOfStock'  => ['http://schema.org/OutOfStock'],
            'bare OutOfStock'  => ['OutOfStock'],
            'SoldOut'          => ['https://schema.org/SoldOut'],
            'Discontinued'     => ['https://schema.org/Discontinued'],
        ];
    }

    #[DataProvider('soldOutAvailabilityCases')]
    public function test_marks_sold_out_offers_unavailable_regardless_of_prefix(string $availability): void
    {
        $out = $this->extract($this->product([
            'offers' => ['@type' => 'Offer', 'price' => '24.00', 'availability' => $availability],
        ]));

        $this->assertCount(1, $out);
        $this->assertFalse($out[0]['variants'][0]['available'], "availability={$availability} must read as sold out");
    }

    public function test_unspecified_availability_defaults_to_in_stock(): void
    {
        $out = $this->extract($this->product([
            'offers' => ['@type' => 'Offer', 'price' => '24.00'],
        ]));

        $this->assertTrue($out[0]['variants'][0]['available']);
    }

    public function test_parses_a_currency_symbol_price_instead_of_dropping_it(): void
    {
        // "$24.00" would (float)-cast to 0.0 and get dropped as non-positive.
        $out = $this->extract($this->product([
            'offers' => ['@type' => 'Offer', 'price' => '$24.00', 'availability' => 'https://schema.org/InStock'],
        ]));

        $this->assertCount(1, $out);
        $this->assertSame(24.0, $out[0]['variants'][0]['price']);
    }

    public function test_reads_aggregate_offer_low_price_with_availability(): void
    {
        $out = $this->extract($this->product([
            'offers' => ['@type' => 'AggregateOffer', 'lowPrice' => '22.50', 'availability' => 'https://schema.org/InStock'],
        ]));

        $this->assertSame(22.5, $out[0]['variants'][0]['price']);
        $this->assertTrue($out[0]['variants'][0]['available']);
    }

    public function test_extracts_only_product_objects_from_a_graph(): void
    {
        $out = $this->extract([
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'Organization', 'name' => 'Roaster Test'],
                ['@type' => 'WebSite', 'name' => 'Site'],
                $this->product(),
            ],
        ]);

        $this->assertCount(1, $out);
        $this->assertSame('Ethiopia Yirgacheffe 250g', $out[0]['name']);
    }

    public function test_extracts_a_top_level_array_of_products(): void
    {
        $out = $this->extract([
            $this->product(['name' => 'Kenya AA 340g']),
            $this->product(['name' => 'Colombia Huila 250g']),
        ]);

        $this->assertCount(2, $out);
        $this->assertEqualsCanonicalizing(
            ['Kenya AA 340g', 'Colombia Huila 250g'],
            array_column($out, 'name')
        );
    }

    public function test_skips_non_coffee_products(): void
    {
        // "V60 Dripper" is gear even though it has a gram-like token.
        $out = $this->extract($this->product(['name' => 'V60 Dripper 250g']));

        $this->assertCount(0, $out);
    }

    public function test_skips_products_with_no_parseable_bag_size(): void
    {
        $out = $this->extract($this->product(['name' => 'Ethiopia Yirgacheffe']));

        $this->assertCount(0, $out);
    }

    public function test_resolves_a_relative_product_url_against_the_origin(): void
    {
        $out = $this->extract($this->product(['url' => '/products/yirg']));

        $this->assertSame('https://roaster.test/products/yirg', $out[0]['product_url']);
    }

    public function test_takes_the_first_image_when_image_is_an_array(): void
    {
        $out = $this->extract($this->product([
            'image' => ['https://cdn.test/a.jpg', 'https://cdn.test/b.jpg'],
        ]));

        $this->assertSame('https://cdn.test/a.jpg', $out[0]['image_url']);
    }

    public function test_no_ld_json_yields_no_products(): void
    {
        $out = $this->scraper->extractProductsFromHtml('<html><body>nothing here</body></html>', $this->origin);

        $this->assertSame([], $out);
    }

    public function test_platform_key_and_fallback_can_handle(): void
    {
        $this->assertSame('generic', $this->scraper->platformKey());
        $this->assertTrue($this->scraper->canHandle('https://anything.example'));
    }

    public function test_malformed_json_yields_no_products(): void
    {
        $html = '<html><head><script type="application/ld+json">{not valid json</script></head><body></body></html>';

        $this->assertSame([], $this->scraper->extractProductsFromHtml($html, $this->origin));
    }

    public function test_skips_products_with_no_price(): void
    {
        $out = $this->extract($this->product(['offers' => null]));

        $this->assertCount(0, $out);
    }
}
