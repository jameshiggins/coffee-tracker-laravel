<?php

namespace Tests\Unit\Scraping;

use App\Services\Scraping\ShippingPolicyScraper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Live-audit findings: 35 of 115 roasters had shipping_notes that were
 * the ENTIRE scraped policy page text — header + nav + "Skip to content"
 * + footer + actual policy + copyright. The old "first sentence with
 * 'shipping'" extractor matched the duplicated page title before any
 * real policy content, leaving useless garbage like:
 *
 *   "Shipping policy – Rosso Coffee Shipping policy | Rosso Coffee
 *    Skip to content Spend $75."
 *
 * These tests pin the better behavior: strip the chrome, then prefer
 * sentences with high-signal phrases (free over $X, ships within N days,
 * delivery times, etc.).
 */
class ShippingPolicyScraperTest extends TestCase
{
    private function scraper(): ShippingPolicyScraper
    {
        return new ShippingPolicyScraper();
    }

    private function fakePolicyPage(string $bodyText): void
    {
        Http::fake([
            '*shipping*' => Http::response("<html><body>{$bodyText}</body></html>", 200),
            '*' => Http::response('', 404),
        ]);
    }

    public function test_extracts_real_policy_sentence_not_page_chrome(): void
    {
        // Rosso shape — the user's reported case.
        $this->fakePolicyPage(implode(' ', [
            'Shipping policy – Rosso Coffee Skip to content Search Sign in Cart',
            '<p>Once your order is received it will be processed and shipped within 1-3 days.</p>',
            '<p>Free shipping across Canada on orders over $75.</p>',
            'Privacy Terms Copyright 2026',
        ]));

        $r = $this->scraper()->fetch('https://example.com');

        $this->assertNotNull($r['shipping_notes']);
        $this->assertStringNotContainsString('Skip to content', $r['shipping_notes']);
        $this->assertStringNotContainsString('Sign in', $r['shipping_notes']);
        $this->assertStringNotContainsString('Copyright', $r['shipping_notes']);
        $this->assertStringContainsString('1-3 days', $r['shipping_notes'],
            'high-signal "ships within N days" phrase should make it into the note');
    }

    public function test_extracts_quebec_french_policy_chrome_too(): void
    {
        // 94 Celcius / Café Pista / Café Saint-Henri / Cantook shape:
        // "Politique d'expédition" page wrapped in nav.
        $this->fakePolicyPage(implode(' ', [
            "Politique d'expédition Aller au contenu Facebook Instagram",
            '<p>Livraison gratuite au Québec sur les commandes de plus de 50$.</p>',
            'Conditions générales Politique de confidentialité',
        ]));

        $r = $this->scraper()->fetch('https://example.com');

        $this->assertNotNull($r['shipping_notes']);
        $this->assertStringNotContainsString('Aller au contenu', $r['shipping_notes']);
        $this->assertStringNotContainsString('Facebook', $r['shipping_notes']);
        $this->assertStringContainsString('Livraison gratuite', $r['shipping_notes']);
    }

    public function test_existing_threshold_extraction_still_works(): void
    {
        // Regression guard: the free-over-$N extraction was working —
        // chrome cleanup shouldn't break it.
        $this->fakePolicyPage(implode(' ', [
            'Skip to content',
            '<p>Free shipping on orders over $75 across Canada.</p>',
            '<p>Standard delivery is $12.</p>',
        ]));

        $r = $this->scraper()->fetch('https://example.com');

        $this->assertSame(75.0, $r['free_shipping_over']);
    }

    public function test_returns_nulls_when_no_policy_page_responds(): void
    {
        Http::fake(['*' => Http::response('', 404)]);

        $r = $this->scraper()->fetch('https://example.com');

        $this->assertNull($r['shipping_cost']);
        $this->assertNull($r['free_shipping_over']);
        $this->assertNull($r['shipping_notes']);
    }

    public function test_chrome_only_page_returns_null_note_not_garbage(): void
    {
        // Page that's all nav / footer with NO actual policy content.
        // Better to return null than to surface chrome as the policy.
        $this->fakePolicyPage('Skip to content Sign in Cart Search Menu Privacy Terms Copyright 2026');

        $r = $this->scraper()->fetch('https://example.com');

        $this->assertNull($r['shipping_notes'],
            'a page containing only nav chrome must NOT produce a shipping_notes string');
    }
}
