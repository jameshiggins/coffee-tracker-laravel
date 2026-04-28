<?php

namespace Tests\Unit\Scraping;

use App\Services\Scraping\AboutPageScraper;
use PHPUnit\Framework\TestCase;

class AboutPageScraperTest extends TestCase
{
    private function scraper(): AboutPageScraper
    {
        return new AboutPageScraper();
    }

    public function test_extracts_og_description_when_present(): void
    {
        $html = '<html><head>'
            . '<meta property="og:description" content="A small-batch roaster in Vancouver." />'
            . '<meta name="description" content="Other description." />'
            . '</head><body></body></html>';
        $this->assertSame(
            'A small-batch roaster in Vancouver.',
            $this->scraper()->extractFromHtml($html)
        );
    }

    public function test_falls_back_to_meta_description_when_no_og(): void
    {
        $html = '<html><head>'
            . '<meta name="description" content="Filter coffee since 2018.">'
            . '</head></html>';
        $this->assertSame(
            'Filter coffee since 2018.',
            $this->scraper()->extractFromHtml($html)
        );
    }

    public function test_decodes_html_entities(): void
    {
        $html = '<meta property="og:description" content="Tim &amp; co. roasters">';
        $this->assertSame('Tim & co. roasters', $this->scraper()->extractFromHtml($html));
    }

    public function test_returns_null_when_no_description_meta(): void
    {
        $this->assertNull($this->scraper()->extractFromHtml('<html><body>hi</body></html>'));
        $this->assertNull($this->scraper()->extractFromHtml(''));
    }

    public function test_handles_single_quotes_in_meta_attributes(): void
    {
        $html = "<meta property='og:description' content='Friendly hosts.' />";
        $this->assertSame('Friendly hosts.', $this->scraper()->extractFromHtml($html));
    }

    public function test_returns_null_when_og_content_is_empty(): void
    {
        $html = '<meta property="og:description" content="" />';
        // Should fall through to <meta name="description">; here it's missing too.
        $this->assertNull($this->scraper()->extractFromHtml($html));
    }
}
