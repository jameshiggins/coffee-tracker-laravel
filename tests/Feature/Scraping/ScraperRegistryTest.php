<?php

namespace Tests\Feature\Scraping;

use App\Services\Scraping\RoasterScraper;
use App\Services\Scraping\ScraperRegistry;
use PHPUnit\Framework\TestCase;

class ScraperRegistryTest extends TestCase
{
    private function fakeScraper(string $key, bool $handles): RoasterScraper
    {
        return new class($key, $handles) implements RoasterScraper {
            public function __construct(private string $key, private bool $handles) {}
            public function platformKey(): string { return $this->key; }
            public function canHandle(string $url): bool { return $this->handles; }
            public function fetch(string $url): array { return []; }
        };
    }

    public function test_for_returns_scraper_by_platform_key(): void
    {
        $registry = new ScraperRegistry([
            $this->fakeScraper('shopify', false),
            $this->fakeScraper('woocommerce', false),
            $this->fakeScraper('generic', true),
        ]);

        $this->assertSame('shopify', $registry->for('shopify')->platformKey());
        $this->assertSame('generic', $registry->for('generic')->platformKey());
    }

    public function test_for_throws_for_unknown_platform(): void
    {
        $registry = new ScraperRegistry([$this->fakeScraper('shopify', true)]);
        $this->expectException(\RuntimeException::class);
        $registry->for('nonsense');
    }

    public function test_detect_returns_first_scraper_that_can_handle(): void
    {
        $registry = new ScraperRegistry([
            $this->fakeScraper('shopify', false),
            $this->fakeScraper('woocommerce', true),
            $this->fakeScraper('generic', true),
        ]);
        $this->assertSame('woocommerce', $registry->detect('https://example.com')->platformKey());
    }

    public function test_detect_uses_cached_platform_without_probing(): void
    {
        // shopify says false but the cache says shopify; cache wins.
        $registry = new ScraperRegistry([
            $this->fakeScraper('shopify', false),
            $this->fakeScraper('woocommerce', true),
        ]);
        $this->assertSame(
            'shopify',
            $registry->detect('https://example.com', 'shopify')->platformKey()
        );
    }

    public function test_detect_falls_through_to_generic_last_resort(): void
    {
        $registry = new ScraperRegistry([
            $this->fakeScraper('shopify', false),
            $this->fakeScraper('woocommerce', false),
            $this->fakeScraper('generic', true),
        ]);
        $this->assertSame('generic', $registry->detect('https://example.com')->platformKey());
    }
}
