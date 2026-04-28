<?php

namespace App\Services\Scraping;

/**
 * Strategy interface for fetching a roaster's current coffee inventory.
 * Implementations target a specific platform (Shopify / WooCommerce / generic
 * HTML) and return data in a normalized shape so RoasterImporter doesn't care
 * where it came from.
 *
 * The normalized return shape is:
 * [
 *   [
 *     'name'         => string,    // coffee title from the source
 *     'source_id'    => string,    // stable id on the source platform (Shopify product id, Woo id, og:product:id, ...)
 *     'description'  => string,    // cleaned prose from the product page
 *     'image_url'    => ?string,   // first product image URL, if any
 *     'product_url'  => ?string,   // direct link to the product page
 *     'is_blend'     => bool,      // best-effort blend detection
 *     'variants'     => [
 *       [
 *         'grams'      => int,     // bag weight, normalized
 *         'price'      => float,
 *         'available'  => bool,    // in-stock flag
 *         'source_id'  => ?string, // variant id on the source platform, if any
 *       ],
 *       ...
 *     ],
 *   ],
 *   ...
 * ]
 */
interface RoasterScraper
{
    /**
     * Cheap, idempotent probe: does this scraper recognise the URL as belonging
     * to its platform? Implementations can hit a known endpoint (e.g. Shopify's
     * /products.json) or look at HTML signals (Woo's wp-json, generators).
     */
    public function canHandle(string $url): bool;

    /**
     * Fetch and normalize the roaster's coffee inventory. Should throw a
     * \RuntimeException with a helpful message on transport / parse failures
     * — the importer catches these and records them in last_import_error.
     *
     * @return array<int, array> normalized coffee rows; see interface PHPDoc
     */
    public function fetch(string $url): array;

    /**
     * The platform key persisted on roasters.platform once we've successfully
     * fetched once. ScraperRegistry uses this to dispatch on subsequent runs
     * without re-probing.
     */
    public function platformKey(): string;
}
