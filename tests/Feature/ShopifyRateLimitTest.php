<?php

namespace Tests\Feature;

use App\Services\Scraping\ShopifyScraper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Shopify throttles the public products.json per client IP across the whole
 * platform (the "2 errors one night, 60 the next" ops-email pattern). These pin
 * the 429 handling: wait out Retry-After (defaulted, capped) and retry before
 * failing the roaster.
 */
class ShopifyRateLimitTest extends TestCase
{
    private function products(): array
    {
        return ['products' => [[
            'id' => 1, 'title' => 'Ethiopia Yirgacheffe', 'product_type' => 'Coffee', 'tags' => [],
            'body_html' => '', 'handle' => 'yirg',
            'variants' => [['id' => 11, 'title' => '250g', 'price' => '24.00', 'available' => true]],
        ]]];
    }

    public function test_fetch_waits_out_a_429_using_retry_after_then_succeeds(): void
    {
        Sleep::fake();
        Http::fakeSequence()
            ->push('', 429, ['Retry-After' => '7'])
            ->push($this->products(), 200);

        $coffees = (new ShopifyScraper())->fetch('https://busy.example.com');

        $this->assertCount(1, $coffees);
        $this->assertSame('Ethiopia Yirgacheffe', $coffees[0]['name']);
        Sleep::assertSleptTimes(1);
        Sleep::assertSequence([Sleep::for(7)->seconds()]);
    }

    public function test_fetch_defaults_the_wait_when_retry_after_is_absent_and_caps_huge_values(): void
    {
        Sleep::fake();
        Http::fakeSequence()
            ->push('', 429)                              // no Retry-After -> default 5s
            ->push('', 429, ['Retry-After' => '300'])    // huge -> capped to 30s
            ->push($this->products(), 200);

        $coffees = (new ShopifyScraper())->fetch('https://busy.example.com');

        $this->assertCount(1, $coffees);
        Sleep::assertSleptTimes(2);
        Sleep::assertSequence([
            Sleep::for(5)->seconds(),
            Sleep::for(30)->seconds(),
        ]);
    }

    public function test_fetch_gives_up_after_the_retries_are_spent(): void
    {
        Sleep::fake();
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '5'])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('429');

        try {
            (new ShopifyScraper())->fetch('https://swamped.example.com');
        } finally {
            Sleep::assertSleptTimes(2); // two waits, three attempts, then fail
        }
    }

    public function test_can_handle_retries_a_429_probe_instead_of_misreading_not_shopify(): void
    {
        Sleep::fake();
        Http::fakeSequence()
            ->push('', 429, ['Retry-After' => '5'])
            ->push(['products' => []], 200);

        $this->assertTrue((new ShopifyScraper())->canHandle('https://busy.example.com'));
        Sleep::assertSleptTimes(1);
    }
}
