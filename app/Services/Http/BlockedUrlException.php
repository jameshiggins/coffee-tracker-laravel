<?php

namespace App\Services\Http;

/**
 * Thrown when an outbound request targets a non-public (SSRF-prohibited)
 * address. Extends RuntimeException so the scrapers' existing
 * `catch (\Throwable)` blocks treat a blocked URL as an ordinary fetch
 * failure (logged + skipped) rather than crashing the import.
 */
class BlockedUrlException extends \RuntimeException
{
}
