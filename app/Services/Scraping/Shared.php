<?php

namespace App\Services\Scraping;

/**
 * Cross-platform helpers used by every scraper implementation.
 * Static utility class — no state.
 */
final class Shared
{
    /**
     * Parse a bag-size string ("250g", "12oz", "1lb", "1kg", "300 Grams",
     * "5 Pounds", "8 Ounces", "3/4 lb", "1 1/2 lb") into grams. Order
     * matters: fractions first (otherwise "3/4lb" parses as "4lb"), kg
     * before g, lb before oz, and the long-form regexes are anchored on
     * full words so they don't conflict with the short-form abbreviations.
     * Returns null when no recognizable size token is present.
     */
    public static function parseGrams(string $title): ?int
    {
        // Fractions FIRST: "3/4 lb", "1/2 lb", "1 1/4 lb". Without this
        // guard, the bare-integer regexes below would match the trailing
        // denominator ("4lb" inside "3/4 lb" — wrong by ~5x).
        if (preg_match('/(?:(\d+)\s+)?(\d+)\s*\/\s*(\d+)\s*(lbs?|pounds?)\b/i', $title, $m)) {
            $whole = $m[1] !== '' ? (int) $m[1] : 0;
            $num = (int) $m[2];
            $den = (int) $m[3];
            if ($den > 0) {
                $pounds = $whole + ($num / $den);
                return (int) round($pounds * 453.592);
            }
        }
        if (preg_match('/(?:(\d+)\s+)?(\d+)\s*\/\s*(\d+)\s*(oz|ounces?)\b/i', $title, $m)) {
            $whole = $m[1] !== '' ? (int) $m[1] : 0;
            $num = (int) $m[2];
            $den = (int) $m[3];
            if ($den > 0) {
                $ounces = $whole + ($num / $den);
                return (int) round($ounces * 28.3495);
            }
        }
        // Long-form units first (more specific). Use the full word as the boundary.
        if (preg_match('/(\d+(?:\.\d+)?)\s*kilo(?:gram)?s?\b/i', $title, $m)) {
            return (int) round((float) $m[1] * 1000);
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*gram?s?\b/i', $title, $m)) {
            return (int) round((float) $m[1]);
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*pounds?\b/i', $title, $m)) {
            return (int) round((float) $m[1] * 453.592);
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*ounces?\b/i', $title, $m)) {
            return (int) round((float) $m[1] * 28.3495);
        }
        // Short forms — keep these AFTER the long forms so "5 Pounds" doesn't
        // get truncated by /\d+\s*lb\b/ (which fails on "Pounds" anyway because
        // \b is between b and u, not after a digit, but be explicit).
        if (preg_match('/(\d+(?:\.\d+)?)\s*kg\b/i', $title, $m)) {
            return (int) round((float) $m[1] * 1000);
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*g\b/i', $title, $m)) {
            return (int) round((float) $m[1]);
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*lbs?\b/i', $title, $m)) {
            return (int) round((float) $m[1] * 453.592);
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*oz\b/i', $title, $m)) {
            return (int) round((float) $m[1] * 28.3495);
        }
        return null;
    }

    /**
     * Guzzle options for outbound HTTPS — Windows PHP doesn't ship a CA bundle,
     * so point at the Mozilla bundle we keep under storage/. Falls back to
     * system defaults on environments where the file isn't present.
     */
    public static function clientOptions(): array
    {
        $opts = [];
        $cacert = storage_path('cacert.pem');
        if (is_readable($cacert)) {
            $opts['verify'] = $cacert;
        }
        $opts['headers']['User-Agent'] = 'SpecialtyCoffeeRoasters/1.0 (+contact: directory)';
        return $opts;
    }

    /** Strip an URL down to scheme + host (no path, query, or fragment). */
    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if ($host === '') {
            throw new \RuntimeException("Invalid URL: {$url}");
        }
        return "{$scheme}://{$host}";
    }

    /**
     * Dedupe variants by parsed grams. Roasters often list the same bag size
     * in two units ("12oz" and "340g") which both resolve to 340g and would
     * violate the (coffee_id, bag_weight_grams) unique index. Prefer an
     * available variant over an unavailable one when colliding.
     *
     * Input: array of variants where each has at least 'grams' and 'available'.
     * Output: same shape, sorted ascending by grams, deduped.
     */
    public static function dedupeVariantsByGrams(array $variants): array
    {
        $byGrams = [];
        foreach ($variants as $v) {
            if (!isset($v['grams'])) continue;
            $g = (int) $v['grams'];
            $existing = $byGrams[$g] ?? null;
            $available = (bool) ($v['available'] ?? true);
            if ($existing && $existing['available'] && !$available) continue;
            $byGrams[$g] = $v;
        }
        ksort($byGrams);
        return array_values($byGrams);
    }

    /**
     * Extract a clean roaster-facing size label from a Shopify variant title.
     *
     * Variant titles take many forms:
     *   - "10.6oz"                       → "10.6 oz"
     *   - "300 Grams / Pink Bourbon"     → "300 g"   (drops grind/cultivar suffix)
     *   - "12oz / Whole Bean"            → "12 oz"
     *   - "5lb (SAVE 5%)"                → "5 lb"    (drops promo)
     *   - "Default Title"                → null      (useless)
     *
     * Returns null when the title carries no size info worth showing.
     * The output is the FRIENDLY label — not a parseable measurement.
     */
    public static function extractSourceSizeLabel(string $variantTitle): ?string
    {
        $t = trim($variantTitle);
        if ($t === '' || strcasecmp($t, 'Default Title') === 0) return null;

        // Split on common separators ("/", "|", "·") and pick the part
        // that looks like a size.
        $parts = preg_split('/\s*[\/|·]\s*/', $t);
        foreach ($parts as $part) {
            // Strip parenthetical promos: "5lb (SAVE 5%)" → "5lb"
            $clean = preg_replace('/\s*\([^)]*\)\s*/', '', $part);
            $clean = trim($clean);
            // Match anything like "10.6oz", "300 Grams", "1 1/2 lb", "1kg"
            if (preg_match('/^\s*\d+(?:[.,]\d+)?\s*(?:g|gram|grams|kg|kilo|kilos|oz|ounce|ounces|lb|lbs|pound|pounds)\s*$/i', $clean)
                || preg_match('/^\s*\d+\s*\/\s*\d+\s*(?:lb|lbs|oz|ounce|pound)s?\s*$/i', $clean)
                || preg_match('/^\s*\d+\s+\d+\s*\/\s*\d+\s*(?:lb|lbs|oz|ounce|pound)s?\s*$/i', $clean)) {
                return self::normalizeSizeLabel($clean);
            }
        }
        // No labelled size found — accept the whole stripped title as long
        // as it isn't too long (prevents storing "Whole Bean" / grind names).
        $cleanWhole = preg_replace('/\s*\([^)]*\)\s*/', '', $t);
        if (mb_strlen($cleanWhole) <= 30 && preg_match('/\d/', $cleanWhole)) {
            return self::normalizeSizeLabel($cleanWhole);
        }
        return null;
    }

    /** Tighten spacing/casing on a size label: "10.6oz" → "10.6 oz". */
    private static function normalizeSizeLabel(string $s): string
    {
        $s = trim($s);
        // Add a space between the number and the unit if missing.
        $s = preg_replace('/(\d)([a-z])/i', '$1 $2', $s);
        // Normalize unit casing — lowercase oz/lb/g/kg.
        $s = preg_replace_callback(
            '/\b(g|gram|grams|kg|kilo|kilos|oz|ounce|ounces|lb|lbs|pound|pounds)\b/i',
            fn ($m) => strtolower($m[0]),
            $s
        );
        // Collapse multi-spaces.
        return preg_replace('/\s+/', ' ', $s);
    }

    /**
     * Variant-level reject. Some product titles look fine ("Donut Shop Blend")
     * but their variants are multipacks / portion packs / sample flights
     * that shouldn't be imported as ordinary bag-of-beans variants.
     * Examples that hit this: "42 x 2.5 oz Portion Packs", "12 x 250g sample",
     * "Pods", "Capsules", "K-Cups".
     *
     * Returns TRUE when the variant title is bad and should be skipped.
     */
    public static function isBadVariantTitle(string $variantTitle): bool
    {
        $t = strtolower(trim($variantTitle));
        if ($t === '') return false;
        // NxY multipack: "42 x 2.5 oz", "12 × 12g", "3x 250 g" — these are
        // bundles, not single bags. Per-bag price computation breaks because
        // we'd record one bag's grams against the bundle's total price.
        if (preg_match('/\b\d+\s*[x×]\s*\d+(?:\.\d+)?\s*(?:g|gram|grams|kg|oz|ounce|ounces|lb|lbs|pound|pounds)\b/u', $t)) return true;
        // "Case of 12", "Box of 6", "Pack of 4", "Bundle of 3", "Set of 2",
        // "12-pack", "6 pack" — same problem: multi-bag containers priced
        // per-case, not per-bag. Ace Coffee Roasters does this with
        // titles like "Case of 12 (340G)" — that's $X for 12×340g, not 340g.
        if (preg_match('/\b(case|box|pack|bundle|set|carton|crate)\s+of\s+\d+/i', $t)) return true;
        if (preg_match('/\b\d+[\s-]?(?:pack|case|bundle|count)\b/i', $t)) return true;
        // Pre-portioned packets / pods / capsules / k-cups / drip bags.
        if (preg_match('/\b(portion\s+packs?|frac[\s-]*packs?|fractional\s+packs?|sachets?|single[\s-]*serve|pre[\s-]*portioned|drip\s+bags?|k[\s-]*cups?|nespresso|capsules?|pods?)\b/', $t)) return true;
        // Sampler / tasting flight variants.
        if (preg_match('/\b(sample\s+(?:pack|set|flight)|tasting\s+(?:flight|set))\b/', $t)) return true;
        return false;
    }

    /**
     * Should this product be imported as a coffee bean?
     * Excludes gear, gift cards, subscriptions, sample packs, chocolate, tea.
     * Includes single-origins, blends, decaf — anything that's a bag of beans.
     *
     * Many specialty roasters categorize products by brewing method instead
     * of the literal word "coffee" (e.g. Phil & Sebastian uses
     * product_type="Filter"). So we accept brewing-method types AND fall
     * back to checking tags, which most Shopify roasters set explicitly.
     */
    public static function looksLikeCoffee(string $title, string $productType = '', array $tags = []): bool
    {
        $type = strtolower($productType);
        $titleLower = strtolower($title);
        $tagsLower = array_map(fn ($t) => strtolower(trim((string) $t)), $tags);
        $tagStr = ' ' . implode(' ', $tagsLower) . ' ';

        // Hard exclusions by product type — gear, gift cards, classes, etc.
        // Bundle / sample-flight / instant / capsule pods aren't whole-bean
        // bags-of-coffee for the purposes of this directory.
        $excludeTypes = ['equipment', 'gear', 'merch', 'merchandise', 'gift card', 'subscription',
                         'apparel', 'class', 'workshop', 'event', 'chocolate', 'tea', 'syrup', 'milk',
                         'bundle', 'capsule', 'pod', 'instant', 'insurance', 'goods', 'shopstorm',
                         'card', 'voucher', 'cleaning', 'cleaner', 'descaling', 'descaler',
                         'accessory', 'accessories', 'tool', 'book', 'ebook'];
        foreach ($excludeTypes as $bad) {
            if (str_contains($type, $bad)) return false;
        }
        // Hard exclusions by title keywords.
        if (str_contains($titleLower, 'gift card') || str_contains($titleLower, 'subscription')) return false;
        if (str_contains($titleLower, 'sample set') || str_contains($titleLower, 'sample pack')) return false;
        if (str_contains($titleLower, 'sample') && (str_contains($titleLower, 'add-on') || str_contains($titleLower, 'add on'))) return false;
        if (preg_match('/\bsample sets?\b/', $titleLower) || str_contains($titleLower, 'tasting set')) return false;

        // Vessels, gear, brewing kit, apparel. All patterns allow plurals
        // (Mugs, Filters) via `s?` and word boundaries to avoid e.g. "Mug"
        // matching inside an unrelated word.
        $gearPattern = '/\b(' . implode('|', [
            'chocolates?', 'cacao', 'teas?', 'matcha', 'yerba\s+mate', 'rooibos',
            'tisane', 'herbal\s+tea', 'chai', 'kombucha', 'soda', 'juices?',
            'merchs?', 't-shirts?', 'hoodies?',
            'mugs?', 'tumblers?', 'bottles?', 'grinders?', 'kettles?', 'brewers?',
            'brewing\s+kits?', 'makers?', 'machines?', 'presses?', 'carafes?',
            'filter\s+papers?', 'paper\s+filters?', 'jugs?', 'flasks?', 'spoons?',
            'scoops?', 'scales?', 'thermometers?', 'timers?', 'tampers?',
            'cups?', 'glasses?', 'aeropress', 'french\s+press', 'chemex', 'v60',
            'kalita', 'moka', 'hario', 'fellow', 'kinto', 'acaia',
            // Reusable-cup brands. These are concatenated words in product
            // titles (HuskeeCup, KeepCup, JoCo) that wouldn't match a `\bcups?\b`
            // boundary, so list them explicitly.
            'huskee', 'huskeecup', 'keepcup', 'keep\s*cup', 'stojo', 'joco',
            'rcup', 'reusables?',
        ]) . ')\b/';
        if (preg_match($gearPattern, $titleLower)) return false;

        // Bundles / sample sets / gift bundles.
        if (preg_match('/\b(bundles?|tasting\s+flights?|tasting\s+sets?|sample\s+packs?|gift\s+sets?|gift\s+box(?:es)?|gift\s+(?:bag|kit))\b/', $titleLower)) return false;
        if (preg_match('/\b(capsules?|pods?|instant)\b/', $titleLower)) return false;

        // Gift cards / pre-paid / vouchers / merchandise cards.
        if (preg_match('/\b(prepaid|pre-paid|voucher|gift\s+cards?|coffee\s+cards?|punch\s+cards?|loyalty\s+cards?)\b/', $titleLower)) return false;

        // Cleaning chemistry — Cafiza, Urnex, descaler, etc. all sneak in
        // because their titles literally contain "coffee" (e.g. "coffee
        // pot cleaner", "coffee machine descaler", "Cafetto Brew Clean").
        if (preg_match('/\b(cleaners?|cleaning|descaler|descaling|cafiza|urnex|cafetto|puly\s*caff|rinza|brew\s+clean(?:er)?|tablet|tablets|powder\s+(?:cleaner|descal))\b/', $titleLower)) return false;

        // Books / merch / wrapping.
        if (preg_match('/\b(books?|ebooks?|guide\s*books?|stickers?|posters?|patches?|pins?|magnets?|keychains?|bag\s*\(reusable\)|reusable\s+bags?|totes?)\b/', $titleLower)) return false;

        // Pot of cleaner / pot of grinds — not a coffee bag.
        if (preg_match('/\bcoffee\s+pots?\b/', $titleLower)) return false;

        // Hot cocoa / drinking chocolate — beverage powders that aren't
        // coffee. Bare "cocoa" stays allowed because it's a common flavor
        // descriptor in real blend names ("Cocoa Mocha Nut", etc.).
        if (preg_match('/\b(hot\s+cocoa|cocoa\s+(?:powder|mix|blend\s+\(drink)|drinking\s+chocolate)\b/', $titleLower)) return false;

        // Raw / green / unroasted coffee — for home-roasters, not the
        // ready-to-brew directory.
        if (preg_match('/\b(raw\s+(?:green\s+)?(?:coffee|beans?)|green\s+(?:coffee|beans?)\b|unroasted|home\s+roast(?:er|ing)?\s+(?:beans?|coffee))\b/', $titleLower)) return false;

        // Wholesale / B2B / office bulk / cafe-supply listings — those are
        // restock channels, not retail bags for an individual buyer.
        if (preg_match('/\b(wholesale|bulk\s+(?:bag|order|coffee|beans?)|cafe\s+supply|office\s+coffee|food\s+service|restaurant\s+(?:supply|account))\b/', $titleLower)) return false;
        // Bracketed wholesale tags — "(Wholesale)", "(Service)", "(Office)",
        // "(Bulk)" — appended to titles by roasters to differentiate B2B SKUs.
        if (preg_match('/\((?:wholesale|service|office|bulk|b2b|cafe|trade|export)\)/i', $titleLower)) return false;
        // Special-order / contact-for-pricing listings — those are B2B
        // request placeholders, not products with a real retail flow.
        if (preg_match('/\b(special\s+order|custom\s+order|contact\s+(?:for|us)|by\s+request|request\s+(?:a|an)?\s*(?:quote|order))\b/', $titleLower)) return false;
        // Generic "5lb Bag Coffee" / "1lb Coffee" placeholder titles with
        // no bean name — those are catch-all SKUs for resellers/B2B.
        if (preg_match('/^\s*\d+\s*(?:lb|lbs|kg|g|oz|gram|ounce|pound)s?\s+(?:bag\s+)?coffee\s*$/', $titleLower)) return false;
        // Sample/gift packaging that isn't really retail.
        if (preg_match('/\b(gift\s+size|gift\s+bag|miniature|tiny\s+sample|trial\s+(?:size|bag))\b/', $titleLower)) return false;
        // Pre-portioned / single-serve / sachet / fractional packs — these
        // are coffee but priced per-portion not per-gram, which screws up
        // the directory's $/g comparison. Also excludes "frac-pack" hardware.
        if (preg_match('/\b(portion\s+packs?|frac[\s-]*packs?|fractional\s+packs?|sachets?|single[\s-]*serve|pre[\s-]*portioned|pour[\s-]*overs?\s+packets?|drip\s+bags?|steeped\s+coffee)\b/', $titleLower)) return false;
        // Shipping fees, drink-credit boxes, gift cards in disguise.
        if (preg_match('/\b(shipping\s+(?:under|over|fee|charge|to|for)|barista\s+box|drink\s+(?:credit|card)|coffee\s+credit)\b/', $titleLower)) return false;
        // "Flavoured/Flavored" coffee = artificially flavored, falls outside
        // the specialty-coffee scope this directory is built for.
        if (preg_match('/\b(flavou?red|hazelnut\s+flavou?red|vanilla\s+flavou?red|french\s+vanilla|caramel\s+flavou?red)\s+coffee\b/', $titleLower)) return false;

        // French (Quebec roasters): abonnement (subscription), prépayé,
        // carte cadeau (gift card), nettoyant (cleaner), tasse (cup), etc.
        if (preg_match('/\b(abonnement|pr[ée]pay[ée]|carte\s+cadeau|nettoyant|tasses?|mat[ée]riel|cadeau)\b/u', $titleLower)) return false;
        // French sample boxes / sample bundles ("Boîte d'échantillons",
        // "Coffret découverte", "Trousse de dégustation") — multi-bag B2B
        // offerings, not retail.
        if (preg_match('/\b(bo[îi]te\s+d[\'’]?\s*[ée]chantillons?|coffret\s+d[ée]couverte|trousse\s+(?:de\s+)?d[ée]gustation|[ée]chantillons?\s+(?:de\s+)?caf[ée]|caf[ée]s?\s+d[ée]couverte)\b/u', $titleLower)) return false;
        // Multipack pattern *in the product title* — "(6 x 100g)",
        // "(12 × 250 g)", "3 x 1lb". Catches the same shape as the
        // variant-level guard but at the product-title level.
        if (preg_match('/\b\d+\s*[x×]\s*\d+(?:[.,]\d+)?\s*(?:g|gram|grams|kg|oz|ounce|ounces|lb|lbs|pound|pounds)\b/u', $titleLower)) return false;

        // Positive signals — any of these = coffee.
        // 1) product_type contains a coffee/brew-method keyword
        $coffeeTypeKeywords = ['coffee', 'bean', 'filter', 'espresso', 'drip', 'pour over',
                               'pour-over', 'whole bean', 'wholebean', 'ground', 'omni', 'roast'];
        foreach ($coffeeTypeKeywords as $kw) {
            if ($kw !== '' && str_contains($type, $kw)) return true;
        }
        // 2) tags include a coffee marker (Shopify roasters tag heavily)
        $coffeeTagKeywords = ['coffee', 'beans', 'single origin', 'single-origin', 'blend',
                              'espresso', 'filter', 'decaf', 'whole bean'];
        foreach ($coffeeTagKeywords as $kw) {
            if (str_contains($tagStr, ' ' . $kw . ' ') || str_contains($tagStr, $kw)) return true;
        }
        // 3) title literally mentions coffee/blend/espresso/etc.
        if (preg_match('/\b(coffee|espresso|blend|decaf|single[- ]origin)\b/', $titleLower)) return true;

        // 4) No type, no tags, no title signal — accept by default. The
        // downstream parseGrams() check will drop anything that lacks a
        // bag-size variant (the real proof it's a bag of beans).
        if ($type === '' && empty($tagsLower)) return true;

        return false;
    }

    /**
     * Best-effort blend detection. Two signals:
     * 1) Explicit: "blend" appears in title/tags/product_type.
     * 2) Probabilistic: "Espresso" tag without "Single Origin" tag → blend
     *    (espresso products are blends ~90% of the time at specialty roasters).
     */
    public static function isBlend(string $title, string $productType, array $tags): bool
    {
        $tagsLower = array_map(fn ($t) => strtolower(trim((string) $t)), $tags);
        $tagStr = implode(' ', $tagsLower);
        $haystack = strtolower(implode(' | ', [$title, $productType, $tagStr]));

        if (str_contains($haystack, 'blend')) return true;

        $isSingleOrigin = str_contains($tagStr, 'single origin') || str_contains($tagStr, 'single-origin');
        $isEspresso = in_array('espresso', $tagsLower, true) || str_contains($title, 'Espresso');
        return $isEspresso && !$isSingleOrigin;
    }
}
