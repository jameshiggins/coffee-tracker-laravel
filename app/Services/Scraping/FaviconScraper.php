<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Http;

/**
 * Find the best favicon / apple-touch-icon URL for a roaster's homepage.
 *
 * Priority (largest, highest-quality first):
 *   1. <link rel="apple-touch-icon">          (180×180 PNG, modern)
 *   2. <link rel="icon" type="image/png">     (any size; prefer biggest)
 *   3. <link rel="shortcut icon">             (legacy)
 *   4. <link rel="icon">                      (anything)
 *   5. /apple-touch-icon.png                  (root convention)
 *   6. /favicon.ico                           (universal fallback)
 *
 * Returns an absolute URL. Returns null when nothing usable is found —
 * the API layer falls back to Google's S2 favicon service at render time.
 *
 * Best-effort: errors are swallowed and return null. Never throws.
 */
class FaviconScraper
{
    public function fetch(string $websiteUrl): ?string
    {
        $origin = Shared::origin($websiteUrl);

        try {
            $response = Http::timeout(8)
                ->withOptions(Shared::clientOptions())
                ->get($origin);
            if (!$response->ok()) return $this->checkRootFallbacks($origin);
            $html = $response->body();
        } catch (\Throwable) {
            return $this->checkRootFallbacks($origin);
        }

        // Parse <link rel="..."> tags. We only care about icon-class rels.
        $candidates = [];
        if (preg_match_all('/<link\b([^>]*)>/i', $html, $matches)) {
            foreach ($matches[1] as $attrs) {
                $rel = $this->attr($attrs, 'rel');
                if (!$rel) continue;
                $rel = strtolower($rel);
                $href = $this->attr($attrs, 'href');
                if (!$href) continue;
                $sizes = $this->attr($attrs, 'sizes') ?? '';
                $type = strtolower($this->attr($attrs, 'type') ?? '');
                if (str_contains($rel, 'apple-touch-icon')) {
                    $candidates[] = ['priority' => 1, 'sizePx' => $this->parseSizes($sizes, 180), 'href' => $href, 'type' => $type];
                } elseif ($rel === 'icon' && str_contains($type, 'png')) {
                    $candidates[] = ['priority' => 2, 'sizePx' => $this->parseSizes($sizes, 32), 'href' => $href, 'type' => $type];
                } elseif (str_contains($rel, 'shortcut icon')) {
                    $candidates[] = ['priority' => 3, 'sizePx' => $this->parseSizes($sizes, 16), 'href' => $href, 'type' => $type];
                } elseif (str_contains($rel, 'icon')) {
                    $candidates[] = ['priority' => 4, 'sizePx' => $this->parseSizes($sizes, 16), 'href' => $href, 'type' => $type];
                }
            }
        }

        // Sort: lower priority first, then biggest size within a priority.
        usort($candidates, function ($a, $b) {
            if ($a['priority'] !== $b['priority']) return $a['priority'] <=> $b['priority'];
            return $b['sizePx'] <=> $a['sizePx'];
        });

        foreach ($candidates as $c) {
            $abs = $this->resolveUrl($origin, $c['href']);
            if ($abs && $this->urlReachable($abs)) return $abs;
        }

        return $this->checkRootFallbacks($origin);
    }

    private function checkRootFallbacks(string $origin): ?string
    {
        foreach (['/apple-touch-icon.png', '/apple-touch-icon-precomposed.png', '/favicon.ico'] as $path) {
            $url = $origin . $path;
            if ($this->urlReachable($url)) return $url;
        }
        return null;
    }

    private function urlReachable(string $url): bool
    {
        try {
            $r = Http::timeout(5)
                ->withOptions(Shared::clientOptions())
                ->withHeaders(['Accept' => 'image/*'])
                ->get($url);
            return $r->ok() && $r->header('Content-Length') !== '0';
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveUrl(string $origin, string $href): ?string
    {
        $href = trim($href);
        if ($href === '') return null;
        if (str_starts_with($href, '//')) {
            return 'https:' . $href;
        }
        if (preg_match('/^https?:\/\//i', $href)) {
            return $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        return $origin . '/' . ltrim($href, './');
    }

    private function attr(string $attrs, string $name): ?string
    {
        // Match attribute="value" / attribute='value' / attribute=value
        $name = preg_quote($name, '/');
        if (preg_match('/\b' . $name . '\s*=\s*"([^"]*)"/i', $attrs, $m)) return $m[1];
        if (preg_match("/\b{$name}\s*=\s*'([^']*)'/i", $attrs, $m)) return $m[1];
        if (preg_match('/\b' . $name . '\s*=\s*([^\s>]+)/i', $attrs, $m)) return $m[1];
        return null;
    }

    /** "180x180" → 180. "any" → fallback. Empty → fallback. */
    private function parseSizes(string $sizes, int $fallback): int
    {
        if ($sizes === '' || strtolower($sizes) === 'any') return $fallback;
        // Pick the biggest if multiple ("16x16 32x32 64x64").
        $best = 0;
        if (preg_match_all('/(\d+)x\d+/i', $sizes, $m)) {
            foreach ($m[1] as $n) {
                $n = (int) $n;
                if ($n > $best) $best = $n;
            }
        }
        return $best > 0 ? $best : $fallback;
    }
}
