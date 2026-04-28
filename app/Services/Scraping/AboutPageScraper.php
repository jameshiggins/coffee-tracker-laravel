<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Http;

/**
 * Best-effort fetcher for a roaster's "about us" blurb. Only used to backfill
 * roasters.description when an admin hasn't manually entered one.
 *
 * Strategy (Q4 hybrid):
 *   1. Try /pages/about — the Shopify convention.
 *   2. Fall back to the homepage.
 *   3. On each page, grab og:description first, then <meta name="description">.
 *   4. Return null on any failure — never throws, never blocks the parent import.
 */
class AboutPageScraper
{
    public function fetch(string $url): ?string
    {
        $origin = Shared::origin($url);

        foreach ([$origin . '/pages/about', $origin] as $candidate) {
            $blurb = $this->extractFromUrl($candidate);
            if ($blurb !== null) return $blurb;
        }
        return null;
    }

    private function extractFromUrl(string $url): ?string
    {
        try {
            $response = Http::timeout(10)->withOptions(Shared::clientOptions())->get($url);
            if (!$response->ok()) return null;
            return $this->extractFromHtml($response->body());
        } catch (\Throwable) {
            return null;
        }
    }

    public function extractFromHtml(string $html): ?string
    {
        // og:description first — the curated tweet-length description the
        // roaster wrote about themselves for social previews.
        if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
            $blurb = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($blurb !== '') return $blurb;
        }
        // Fallback to plain <meta name="description"> — usually similar.
        if (preg_match('/<meta[^>]+name=["\']description["\'][^>]*content=["\']([^"\']+)["\']/i', $html, $m)) {
            $blurb = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($blurb !== '') return $blurb;
        }
        return null;
    }
}
