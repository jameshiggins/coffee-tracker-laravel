<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\Http;

/**
 * Best-effort shipping-policy scraper. Tries the conventional URLs each
 * platform exposes, regexes the page text for "free over $X" / "$X flat
 * rate" patterns, and returns whatever it can extract. Always returns
 * a defined shape (nulls when nothing found) — never throws.
 *
 * This is heuristic; expect ~40-60% hit rate. Roasters phrase shipping
 * policies wildly differently. The output is meant to be a useful
 * default that admins can override manually.
 */
class ShippingPolicyScraper
{
    private const POLICY_PATHS = [
        '/policies/shipping-policy',     // Shopify default
        '/policies/shipping',
        '/pages/shipping',               // custom Shopify pages
        '/pages/shipping-policy',
        '/pages/shipping-returns',
        '/shipping-returns',             // WooCommerce common
        '/shipping',
        '/shipping-policy',
    ];

    /**
     * @return array{shipping_cost: ?float, free_shipping_over: ?float, shipping_notes: ?string}
     */
    public function fetch(string $websiteUrl): array
    {
        $origin = Shared::origin($websiteUrl);
        $text = null;

        foreach (self::POLICY_PATHS as $path) {
            try {
                $r = Http::timeout(8)->withOptions(Shared::clientOptions())->get($origin . $path);
                if (!$r->ok()) continue;
                $body = $r->body();
                // Strip HTML to plain text
                $body = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $body);
                $body = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $body);
                $body = strip_tags($body);
                $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $body = preg_replace('/\s+/', ' ', $body);
                $body = trim($body);
                if (strlen($body) < 50) continue;
                $text = $body;
                break; // first successful policy page wins
            } catch (\Throwable) {
                continue;
            }
        }

        if (!$text) {
            return ['shipping_cost' => null, 'free_shipping_over' => null, 'shipping_notes' => null];
        }

        return [
            'shipping_cost' => $this->extractFlatRate($text),
            'free_shipping_over' => $this->extractFreeOverThreshold($text),
            'shipping_notes' => $this->extractShortNote($text),
        ];
    }

    /**
     * Match "free shipping on orders over $50" / "free over $75" / etc.
     * Picks the first plausible amount; ignores order-discount thresholds
     * by requiring the word "shipping" / "delivery" within ~30 chars.
     */
    private function extractFreeOverThreshold(string $text): ?float
    {
        $patterns = [
            '/free\s+(?:shipping|delivery)[^$]{0,40}\$\s*(\d{1,4}(?:\.\d{2})?)/i',
            '/orders?\s+(?:over|above|of|exceeding)\s+\$\s*(\d{1,4}(?:\.\d{2})?)\s+ship\s+free/i',
            '/\$\s*(\d{1,4}(?:\.\d{2})?)\+?\s+(?:gets?\s+)?free\s+(?:shipping|delivery)/i',
            '/(?:free|complimentary)\s+(?:standard\s+)?(?:shipping|delivery)\s+on\s+orders?\s+\$\s*(\d{1,4}(?:\.\d{2})?)/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $text, $m)) {
                $amount = (float) $m[1];
                if ($amount >= 10 && $amount <= 500) return $amount;
            }
        }
        return null;
    }

    /**
     * Match "$10 flat rate shipping" / "shipping is $12" / etc.
     * Conservative — only picks numbers right next to "shipping".
     */
    private function extractFlatRate(string $text): ?float
    {
        $patterns = [
            '/\$\s*(\d{1,3}(?:\.\d{2})?)\s+flat[- ]?rate\s+(?:shipping|delivery)/i',
            '/(?:standard|regular)\s+(?:shipping|delivery)[^$]{0,30}\$\s*(\d{1,3}(?:\.\d{2})?)/i',
            '/(?:shipping|delivery)\s+(?:is|costs?|fee\s+is)\s+\$\s*(\d{1,3}(?:\.\d{2})?)/i',
            '/\$\s*(\d{1,3}(?:\.\d{2})?)\s+shipping\s+fee/i',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $text, $m)) {
                $amount = (float) $m[1];
                if ($amount >= 1 && $amount <= 100) return $amount;
            }
        }
        return null;
    }

    /** First 200 chars of the policy as a fallback note for the admin. */
    private function extractShortNote(string $text): ?string
    {
        $snippet = mb_substr($text, 0, 220);
        // Prefer the first sentence containing "shipping" or "delivery" if present
        if (preg_match('/[^.!?]*\b(shipping|delivery)\b[^.!?]{0,180}[.!?]/i', $text, $m)) {
            $sent = trim($m[0]);
            if (strlen($sent) >= 20 && strlen($sent) <= 240) return $sent;
        }
        return $snippet !== '' ? $snippet . '…' : null;
    }
}
