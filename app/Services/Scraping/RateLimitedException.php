<?php

namespace App\Services\Scraping;

use RuntimeException;

/**
 * The storefront platform answered 429 even after the scraper waited out its
 * Retry-After. Shopify throttles the public products.json PER CLIENT IP across
 * every shop on the platform, so this is a verdict on tonight's run from our
 * one egress IP — not on the roaster. Callers treat it as "not attempted":
 * the importer leaves the roaster's last status alone and the nightly run
 * pauses, then comes back to the roaster at the end.
 */
class RateLimitedException extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds = 0)
    {
        parent::__construct($message);
    }
}
