<?php

namespace App\Services\Scraping;

use App\Models\Roaster;
use RuntimeException;

/**
 * Picks the right scraper for a given roaster URL. The first time a roaster
 * is imported, walks the scraper list in order and returns the first whose
 * canHandle() succeeds. Subsequent imports skip the probe by reading
 * roasters.platform — set by RoasterImporter on a successful run.
 */
class ScraperRegistry
{
    /** Order matters: most specific (cheap-to-detect) first, generic last. */
    private array $scrapers;

    public function __construct(?array $scrapers = null)
    {
        // Order: cheapest probe first, generic catch-all last. Squarespace
        // sits between Woo and Generic because its `?format=json-pretty`
        // detection is lightweight but lower-priority than the platforms
        // with dedicated catalog APIs.
        $this->scrapers = $scrapers ?? [
            new ShopifyScraper(),
            new WooCommerceScraper(),
            new SquarespaceScraper(),
            new GenericHtmlScraper(),
        ];
    }

    /** Look up a scraper by its platformKey. Throws if unknown. */
    public function for(string $platform): RoasterScraper
    {
        foreach ($this->scrapers as $s) {
            if ($s->platformKey() === $platform) return $s;
        }
        throw new RuntimeException("No scraper registered for platform: {$platform}");
    }

    /**
     * Detect the right scraper for a URL, optionally with a hint from a
     * previously-cached platform. Returns the chosen scraper. Does NOT
     * persist anything — the caller (RoasterImporter) writes roasters.platform.
     */
    public function detect(string $url, ?string $cachedPlatform = null): RoasterScraper
    {
        if ($cachedPlatform) {
            // Trust the cache. A platform migration is a rare event; if it
            // happens the user can hit "re-detect" from the admin to clear.
            return $this->for($cachedPlatform);
        }

        foreach ($this->scrapers as $scraper) {
            if ($scraper->canHandle($url)) {
                return $scraper;
            }
        }
        // GenericHtmlScraper.canHandle() returns true unconditionally, so the
        // loop above always returns. If you remove that property, this throw
        // becomes the safety net.
        throw new RuntimeException("No scraper could handle: {$url}");
    }

    /** Convenience for the importer. */
    public function detectForRoaster(Roaster $roaster): RoasterScraper
    {
        if (!$roaster->website) {
            throw new RuntimeException("Roaster {$roaster->name} has no website to import from.");
        }
        return $this->detect($roaster->website, $roaster->platform);
    }
}
