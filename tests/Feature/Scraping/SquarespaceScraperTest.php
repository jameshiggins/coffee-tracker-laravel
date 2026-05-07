<?php

namespace Tests\Feature\Scraping;

use App\Services\Scraping\SquarespaceScraper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Squarespace 7.x exposes any page as JSON via ?format=json-pretty. Shop
 * pages return an `items[]` array; each item has `structuredContent`
 * with variants whose `attributes` carry a Bag Size field and
 * `priceMoney.value` holds the price.
 */
class SquarespaceScraperTest extends TestCase
{
    private function shopPayload(array $items): array
    {
        return ['website' => ['id' => 'abc123'], 'items' => $items];
    }

    private function product(string $id, string $title, array $variants, array $extras = []): array
    {
        return array_merge([
            'id' => $id,
            'title' => $title,
            'urlId' => str_replace(' ', '-', strtolower($title)),
            'fullUrl' => '/coffees/' . str_replace(' ', '-', strtolower($title)),
            'assetUrl' => 'https://img.example.com/' . $id . '.jpg',
            'excerpt' => 'A great coffee.',
            'tags' => [],
            'categories' => [],
            'structuredContent' => [
                '_type' => 'product',
                'productType' => 1,
                'variants' => $variants,
            ],
        ], $extras);
    }

    private function variant(string $id, string $bagSize, string $price, bool $available = true): array
    {
        return [
            'id' => $id,
            'attributes' => ['Bag Size' => $bagSize],
            'priceMoney' => ['currency' => 'CAD', 'value' => $price],
            'unlimited' => false,
            'qtyInStock' => $available ? 5 : 0,
        ];
    }

    public function test_canHandle_returns_true_when_format_json_pretty_yields_squarespace_payload(): void
    {
        Http::fake([
            '*' => Http::response(
                ['website' => ['id' => 'abc']],
                200,
                ['Content-Type' => 'application/json']
            ),
        ]);
        $s = new SquarespaceScraper();
        $this->assertTrue($s->canHandle('https://example.com'));
    }

    public function test_canHandle_returns_false_for_html_response(): void
    {
        Http::fake([
            '*' => Http::response('<html>', 200, ['Content-Type' => 'text/html']),
        ]);
        $s = new SquarespaceScraper();
        $this->assertFalse($s->canHandle('https://example.com'));
    }

    public function test_fetch_extracts_coffee_with_bag_size_and_price(): void
    {
        // First two paths 404 / empty so we fall through to /coffees, the
        // canonical Drumroaster pattern.
        Http::fakeSequence()
            ->push('not found', 404)        // /shop?format=json-pretty
            ->push($this->shopPayload([     // /coffees?format=json-pretty
                $this->product('p1', 'Yirgacheffe', [
                    $this->variant('v1', '300g', '24.00', true),
                    $this->variant('v2', '1kg', '70.00', true),
                ]),
            ]), 200, ['Content-Type' => 'application/json']);

        $s = new SquarespaceScraper();
        $beans = $s->fetch('https://example.com');

        $this->assertCount(1, $beans);
        $this->assertSame('Yirgacheffe', $beans[0]['name']);
        $this->assertSame('p1', $beans[0]['source_id']);
        $this->assertSame('https://example.com/coffees/yirgacheffe', $beans[0]['product_url']);
        $this->assertSame('https://img.example.com/p1.jpg', $beans[0]['image_url']);
        $this->assertCount(2, $beans[0]['variants']);
        $this->assertSame(300, $beans[0]['variants'][0]['grams']);
        $this->assertSame(24.00, $beans[0]['variants'][0]['price']);
        $this->assertTrue($beans[0]['variants'][0]['available']);
        $this->assertSame(1000, $beans[0]['variants'][1]['grams']);
    }

    public function test_fetch_skips_non_coffee_products(): void
    {
        Http::fakeSequence()
            ->push($this->shopPayload([
                $this->product('p1', 'Yirgacheffe Coffee', [$this->variant('v1', '300g', '24.00')]),
                $this->product('p2', 'Branded T-Shirt', [$this->variant('v2', 'Large', '30.00')]),
                $this->product('p3', 'Coffee Mug', [$this->variant('v3', 'Standard', '15.00')]),
            ]), 200, ['Content-Type' => 'application/json']);

        $s = new SquarespaceScraper();
        $beans = $s->fetch('https://example.com');

        $this->assertCount(1, $beans);
        $this->assertSame('Yirgacheffe Coffee', $beans[0]['name']);
    }

    public function test_fetch_returns_empty_array_when_no_shop_path_responds(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $s = new SquarespaceScraper();
        $this->assertSame([], $s->fetch('https://example.com'));
    }

    public function test_qty_in_stock_zero_marks_variant_unavailable(): void
    {
        Http::fakeSequence()->push($this->shopPayload([
            $this->product('p1', 'Burundi Filter Coffee', [
                $this->variant('v1', '250g', '22.00', false),  // out of stock
            ]),
        ]), 200, ['Content-Type' => 'application/json']);

        $s = new SquarespaceScraper();
        $beans = $s->fetch('https://example.com');

        $this->assertCount(1, $beans);
        $this->assertFalse($beans[0]['variants'][0]['available']);
    }
}
