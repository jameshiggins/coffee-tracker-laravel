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

    /**
     * Extract a concise shipping note worth showing to a user. The old
     * "first sentence with 'shipping'" approach matched the duplicated
     * page title before any real content (the "Shipping policy | Roaster
     * Skip to content" garbage class). This version strips nav/footer
     * chrome first, then prefers sentences with HIGH-SIGNAL policy
     * phrases (free over $X, ships within N days, delivery times, …)
     * over the generic word "shipping".
     */
    private function extractShortNote(string $text): ?string
    {
        $clean = $this->stripChrome($text);
        if (mb_strlen($clean) < 30) return null;

        // High-signal policy phrases — these only appear in real policy
        // content, not in nav/footer. English + French (Quebec roasters).
        $signals = [
            // ENG quantitative
            '/orders?\s+(?:over|above|of|exceeding)\s+\$\s*\d+/i',
            '/free\s+(?:shipping|delivery)\s+(?:on\s+|over\s+|across\s+|within\s+|for\s+)/i',
            '/ships?\s+(?:within|in)\s+\d/i',
            '/processed\s+and\s+shipped\s+within\s+\d/i',
            '/delivery\s+(?:within|in|takes?)\s+\d/i',
            '/\d\s*-\s*\d+\s+(?:business\s+)?days?/i',
            '/flat[- ]?rate\s+(?:shipping|delivery)/i',
            // ENG qualitative
            '/we\s+ship\s+(?:to|across|throughout|worldwide|internationally)/i',
            '/shipping\s+(?:rates|costs?|fees?)\s+(?:are|start|begin)/i',
            // FR quantitative
            '/livraison\s+gratuite/i',
            '/commandes?\s+(?:de\s+plus\s+de|sup[ée]rieur(?:es?)?\s+[àa])\s+\d/i',
            '/exp[ée]diti?on\s+(?:gratuite|sous\s+\d|en\s+\d)/i',
            // FR qualitative
            '/nous\s+exp[ée]dions/i',
        ];

        $best = [];
        foreach (explode('.', $clean) as $rawSent) {
            $sent = trim($rawSent);
            if (mb_strlen($sent) < 20 || mb_strlen($sent) > 240) continue;
            foreach ($signals as $sig) {
                if (preg_match($sig, $sent)) {
                    $best[] = $sent;
                    break;
                }
            }
            if (count($best) >= 2) break;
        }

        if (empty($best)) return null;
        $note = implode('. ', $best) . '.';
        // Cap at 280 chars (enough for 1-2 sentences) and strip residual
        // double-spaces just in case.
        $note = preg_replace('/\s+/', ' ', trim($note));
        if (mb_strlen($note) > 280) {
            $note = mb_substr($note, 0, 277) . '…';
        }
        return $note;
    }

    /**
     * Remove nav / header / footer chrome that's not part of the actual
     * policy. The patterns are tuned to common Shopify / Squarespace /
     * WooCommerce + bilingual (EN/FR) templates that the live audit
     * surfaced. Conservative — keeps any sentence that doesn't fully match.
     */
    private function stripChrome(string $text): string
    {
        $chromePatterns = [
            // ENG nav/footer markers — strip the marker, leave surrounding text
            '/\bSkip to (?:content|main content)\b/i',
            '/\bSign (?:in|up)\b/i',
            '/\bMy account\b/i',
            '/\bSearch(?:\s+our\s+(?:site|store))?\b/i',
            '/\bMenu\s+(?:Close|Open)\b/i',
            '/\bClose menu\b/i',
            '/\bCart\s+\(?\d*\)?/i',
            '/\bView cart\b/i',
            '/\bCheckout\b/i',
            '/\bFollow us\b/i',
            '/\bCopyright\s+©?\s*\d{4}/i',
            '/\bAll rights reserved\b/i',
            '/\bPrivacy(?:\s+policy)?\b\s*(?:Terms|Refund|Shipping|Contact)?/i',
            '/\bTerms\s+of\s+(?:service|use)\b/i',
            '/\bRefund\s+policy\b/i',
            '/\bPowered by Shopify\b/i',
            // FR nav/footer markers
            '/\bAller au contenu\b/i',
            '/\bIgnorer et passer au contenu\b/i',
            '/\bPasser au contenu\b/i',
            '/\bMon compte\b/i',
            '/\bPanier\b/i',
            '/\bConditions (?:g[ée]n[ée]rales|d[\'’]utilisation)\b/i',
            '/\bPolitique\s+de\s+(?:confidentialit[ée]|remboursement)\b/i',
            // Common social media noise
            '/\b(?:Facebook|Instagram|Twitter|TikTok|YouTube|Pinterest|LinkedIn)\b/i',
            // Repeated page-title pattern: "Shipping policy – RoasterName Shipping policy"
            '/(Shipping\s+policy|Politique\s+d[\'’]exp[ée]diti?on)\s*[\-–|]\s*[^.]{1,40}\s+\1/iu',
        ];
        foreach ($chromePatterns as $p) {
            $text = preg_replace($p, ' ', $text) ?? $text;
        }
        // Collapse the whitespace gaps the strips leave behind.
        return trim(preg_replace('/\s+/', ' ', $text));
    }
}
