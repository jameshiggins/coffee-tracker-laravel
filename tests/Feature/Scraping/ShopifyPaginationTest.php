<?php

namespace Tests\Feature\Scraping;

use App\Services\Scraping\ShopifyScraper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H4: ShopifyScraper must paginate /products.json (Shopify caps each page at
 * 250). A single un-paginated fetch silently drops the tail of large catalogs
 * and the importer then soft-removes the dropped beans.
 */
class ShopifyPaginationTest extends TestCase
{
    /** A long body so enrichFromMetafields() skips the extra per-product fetch. */
    private const LONG_BODY = '<p>This is a deliberately long product description that exceeds the thin-body threshold so the metafield enrichment pass is skipped during this test, keeping the fake HTTP interactions limited to the catalog pages themselves and nothing else at all.</p>';

    private function products(int $start, int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $id = $start + $i;
            $out[] = [
                'id' => $id,
                'title' => "Single Origin Coffee {$id}",
                'product_type' => 'Coffee',
                'tags' => ['Single Origin'],
                'body_html' => self::LONG_BODY,
                'handle' => "coffee-{$id}",
                'variants' => [
                    ['id' => $id * 10, 'title' => '250g', 'price' => '22.00', 'available' => true],
                ],
            ];
        }

        return $out;
    }

    public function test_fetch_follows_pagination_past_the_first_250(): void
    {
        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $page = (int) ($q['page'] ?? 1);

            return match ($page) {
                1 => Http::response(['products' => $this->products(1, 250)], 200),
                2 => Http::response(['products' => $this->products(251, 7)], 200),
                default => Http::response(['products' => []], 200),
            };
        });

        $coffees = (new ShopifyScraper())->fetch('https://bigroaster.test');

        // 250 (page 1) + 7 (page 2) — the tail beyond 250 is NOT dropped.
        $this->assertCount(257, $coffees);
    }

    public function test_fetch_stops_on_a_short_first_page(): void
    {
        Http::fake([
            '*' => Http::response(['products' => $this->products(1, 3)], 200),
        ]);

        $coffees = (new ShopifyScraper())->fetch('https://smallroaster.test');

        $this->assertCount(3, $coffees);
        // Only one catalog page should have been requested (short page = done).
        Http::assertSentCount(1);
    }
}
