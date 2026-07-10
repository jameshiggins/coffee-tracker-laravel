<?php

namespace App\Services\Scraping;

use App\Services\OriginGazetteer;

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
        // Present as a real browser. An honest bot UA
        // ("SpecialtyCoffeeRoasters/1.0") sails through from a residential IP
        // but scores as high-risk from a datacenter IP (Fly.io) — Cloudflare and
        // similar WAFs on the storefronts then 403/challenge the request. That
        // was silently failing ~a third of the daily import in production while
        // every site imported fine from a laptop. A browser User-Agent + the
        // Accept headers a browser actually sends lowers the bot score enough to
        // pass. Mirrors the Safari-shaped UA CheckLinks already uses for the same
        // reason; keeps a small "roastmap/1.0" honesty tag. Callers that need a
        // different identity (NominatimGeocoder's usage-policy UA) override it
        // via ->withHeaders(), which merges on top of these.
        $opts['headers']['User-Agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
            . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36 roastmap/1.0';
        $opts['headers']['Accept'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,'
            . 'image/avif,image/webp,*/*;q=0.8';
        $opts['headers']['Accept-Language'] = 'en-CA,en;q=0.9';
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
        // Grind-optioned shops (the Agro pattern) emit one variant per grind
        // at the SAME bag size ("340g / Wholebean", …, "340g / French Press").
        // Deep-linking any ground-coffee variant hands a whole-bean buyer a
        // bag of pre-ground — so rank whole bean above everything, then
        // availability. Ties keep the FIRST variant seen (shops list their
        // default grind — typically whole bean — first; the old last-wins
        // behavior is how prod ended up linking French Press).
        $byGrams = [];
        $scores = [];
        foreach ($variants as $v) {
            if (!isset($v['grams'])) continue;
            $g = (int) $v['grams'];
            $isWholeBean = (bool) preg_match('/\bwhole[\s-]*beans?\b/i', (string) ($v['variant_title'] ?? ''));
            $available = (bool) ($v['available'] ?? true);
            $score = ($isWholeBean ? 2 : 0) + ($available ? 1 : 0);
            if (isset($byGrams[$g]) && $score <= $scores[$g]) continue;
            $byGrams[$g] = $v;
            $scores[$g] = $score;
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
     * Find a bag-weight signal inside a product description / excerpt. Strict
     * by design — only accept matches that resolve to a STANDARD specialty-
     * coffee bag size (100, 200, 225, 227, 250, 300, 340, 454, 500, 1000,
     * 2000, 2268 g). The whitelist is what keeps incidental description
     * numbers (altitude "1600 MASL", a "brew 18g" recipe, harvest years) from
     * becoming bag weights.
     *
     * Used as a LAST-RESORT size fallback by scrapers whose variants don't
     * carry a size attribute (the common Squarespace single-size shape, where
     * attributes is `[]` and the weight lives in the excerpt) or whose size
     * lives only in body_html (Shopify). The whitespace-typo pass recovers
     * template artifacts like "2  50G" / "250  G" → 250g.
     */
    public static function parseBodyGrams(string $body): ?int
    {
        if ($body === '') return null;
        $standard = [100, 200, 225, 227, 250, 300, 340, 454, 500, 1000, 2000, 2268];

        // Pass 1: standard "<digits>[unit]" matches.
        if (preg_match_all('/\b(\d+(?:[.,]\d+)?)\s*(g|gram|grams|kg|kilo|kilos|oz|ounce|ounces|lb|lbs|pound|pounds)\b/iu', $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $candidate = self::parseGrams($m[0]);
                if ($candidate !== null && in_array($candidate, $standard, true)) {
                    return $candidate;
                }
            }
        }

        // Pass 2: whitespace-typo recovery for the "2 50G" → 250 pattern.
        // Only accept when concatenating produces a standard size.
        if (preg_match_all('/\b(\d)\s+(\d+)\s*(g|gram|grams|kg|oz|lb)\b/iu', $body, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $combined = (int) ($m[1] . $m[2]);
                if (in_array($combined, $standard, true)) {
                    return $combined;
                }
            }
        }

        return null;
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
    public static function looksLikeCoffee(string $title, string $productType = '', array $tags = [], string $description = ''): bool
    {
        $type = strtolower($productType);
        $titleLower = strtolower($title);
        $tagsLower = array_map(fn ($t) => strtolower(trim((string) $t)), $tags);
        $tagStr = ' ' . implode(' ', $tagsLower) . ' ';

        // Tea/tisane leak (the Anchored pattern): an innocent title
        // ("Chamomile", "Jade Cloud", "Irish Breakfast") with EMPTY
        // product_type and tags — the only tell is the body, which reads as
        // a steeping spec. Require a STRONG tea signal (steeping-spec
        // vocabulary, the tea plant's botanical name) AND no coffee
        // counter-signal anywhere: coffees that merely cite "black tea" as a
        // tasting note don't match the strong signals, and coffee brew
        // guides that say "steep" (AeroPress/French-press instructions)
        // always also say coffee/roast/espresso.
        if ($description !== '') {
            $strongTea = '/\b(steep(?:ing)?\s+(?:temp|temperature|time)|steep\s+for\s+\d'
                . '|camell?ia\s+sinensis|loose[\s-]?leaf|tisanes?'
                . '|infusion\s+(?:aroma|colou?r)|herbal\s+(?:tea|blend|infusion))\b/iu';
            $coffeeSignal = '/\b(coffee|roast(?:ed|ing|s)?|espresso|arabica|robusta|cupping)\b/iu';
            if (preg_match($strongTea, $description)
                && ! preg_match($coffeeSignal, $titleLower . ' ' . $description)) {
                return false;
            }
        }

        // Hard exclusions by product type — gear, gift cards, classes, etc.
        // Bundle / sample-flight / instant / capsule pods aren't whole-bean
        // bags-of-coffee for the purposes of this directory.
        //
        // NOTE: bare brew-method words ("filter", "espresso", "drip", "pour
        // over", "omni", "roast") are deliberately NOT in this list — many
        // specialty roasters use them as the coffee product_type (Phil &
        // Sebastian => "Filter", others => "Espresso"). Equipment instead
        // gets its OWN distinct product_type ("Brewer", "Grinder", "Kettle",
        // "Drip Coffee Makers", …) which is what we exclude here. Big stores
        // with a deep hardware catalog (Rogue Wave: ~470 gear SKUs vs ~260
        // coffees) are the reason this list is exhaustive.
        $excludeTypes = ['equipment', 'gear', 'merch', 'merchandise', 'gift card', 'subscription',
                         'apparel', 'clothing', 'class', 'workshop', 'event', 'chocolate', 'tea',
                         'matcha', 'syrup', 'milk', 'water',
                         'bundle', 'capsule', 'pod', 'instant', 'insurance', 'goods', 'shopstorm',
                         'card', 'voucher', 'cleaning', 'cleaner', 'descaling', 'descaler',
                         'accessory', 'accessories', 'tool', 'book', 'ebook',
                         // Brew hardware / vessels / serveware. These are the
                         // distinct product_type values stores assign to gear.
                         // Kept specific enough that they can't be a substring
                         // of a real coffee product_type ("Coffee", "Filter",
                         // "Espresso", "Beans", "Whole Bean", "Drip", "Omni",
                         // "Roast") — e.g. we use 'drinkware' not 'cup',
                         // 'serveware' not 'glass', so "Whole Bean" / "Filter"
                         // stay clear.
                         'brewer', 'dripper', 'grinder', 'kettle', 'scale', 'server', 'serveware',
                         'drinkware', 'glassware', 'tamper', 'portafilter', 'maker',
                         'machine', 'spoon', 'scoop', 'sticker', 'shirt', 'hoodie',
                         'sweater', 'apron', 'tote bag', 'milk jug', 'pitcher', 'carafe',
                         'tumbler', 'home & garden', 'serving',
                         'holder', 'tray', 'key chain', 'keychain', 'storage',
                         'container', 'post card', 'postcard', 'filters', 'filter paper',
                         'paper filter', 'distributor'];
        foreach ($excludeTypes as $bad) {
            if (str_contains($type, $bad)) return false;
        }
        // Espresso/Coffee-equipment composite product_types — "Espresso
        // Accessories", "Coffee Grinder Accessories", "Coffee Maker &
        // Espresso Machine Accessories". The bare "accessor" token above
        // catches these, but spell out the multi-word forms for clarity and
        // to survive any future tokenizer change.
        if (preg_match('/\b(accessor|equipment|grinder|brewing\s+gear)\b/i', $type)) return false;
        // Wholesale SKUs. Some roasters publicly list wholesale tiers on their
        // storefront alongside retail bags — Moving Coffee prefixes them
        // "WS - ", "WS3 - ", "WS-Max - " (24 of their ~96 products), which
        // imported as regular coffees at fake-cheap per-gram prices AND
        // duplicated the retail bean (three "Bench Maji Gesha" entries). These
        // aren't consumer products. Match a leading WS wholesale prefix + its
        // " - " name delimiter; anchored so no real coffee name is caught (none
        // start with "WS - ").
        if (preg_match('/^\s*ws(?:\d+|-\w+)?\s*-\s/i', $title)) return false;
        // Hard exclusions by title keywords.
        if (str_contains($titleLower, 'gift card') || str_contains($titleLower, 'subscription')) return false;
        if (str_contains($titleLower, 'sample set') || str_contains($titleLower, 'sample pack')) return false;
        if (str_contains($titleLower, 'sample') && (str_contains($titleLower, 'add-on') || str_contains($titleLower, 'add on'))) return false;
        if (preg_match('/\bsample sets?\b/', $titleLower) || str_contains($titleLower, 'tasting set')) return false;

        // Vessels, gear, brewing kit, apparel. All patterns allow plurals
        // (Mugs, Filters) via `s?` and word boundaries to avoid e.g. "Mug"
        // matching inside an unrelated word.
        // NOTE: bare 'chocolates?' was removed from this list because it
        // false-rejected real coffees named for the flavor descriptor
        // (Oso Negro "Chocolate Cake", "Chocolate Cherry Bomb", etc.). The
        // stricter chocolate-product patterns below catch actual chocolate
        // confectionery; product_type='Chocolate' is still hard-rejected
        // via the excludeTypes loop earlier.
        $gearPattern = '/\b(' . implode('|', [
            'cacao', 'teas?', 'matcha', 'yerba\s+mate', 'rooibos',
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

        // Storage-vessel refills & vacuum packs — the empty container OR its
        // refill, not the coffee. Prototype's "100g Vacuum Pack/Jar Refill"
        // ($19, "100g tin refill for top tier coffees … No jar purchase
        // necessary") is the canonical case: it names no gear token the lists
        // above catch, so it read as a bean. We match "vacuum pack" and the
        // rigid-container refills (jar/tin/can/canister/vault refill, either
        // word order) — deliberately NOT a bare "jar", so Rosso's real coffee
        // "Jam Jar / Ethiopia" is untouched, and NOT "bag/pouch refill", which
        // could be an eco refill pouch of actual coffee.
        if (preg_match('/\b(vacuum[\s-]?packs?|(?:jars?|tins?|cans?|canisters?|vaults?)\s+refills?|refills?\s+(?:for\s+)?(?:jars?|tins?|cans?|canisters?|vaults?))\b/', $titleLower)) return false;

        // Hot cocoa / drinking chocolate — beverage powders that aren't
        // coffee. Bare "cocoa" stays allowed because it's a common flavor
        // descriptor in real blend names ("Cocoa Mocha Nut", etc.).
        if (preg_match('/\b(hot\s+cocoa|hot\s+chocolate|cocoa\s+(?:powder|mix|blend\s+\(drink)|drinking\s+chocolate)\b/', $titleLower)) return false;

        // Honey AS A PRODUCT (not a flavor descriptor in a coffee name).
        // Rosso's "Drizzle Honey" is the canonical case — actual raw honey
        // their Shopify lists alongside coffees. Bare "Honey" in a coffee
        // name (e.g. "Honey, Hunny / Guatemala" — a real Rosso coffee, or
        // a process tag like "Red Honey") stays allowed; we only reject
        // when the title is clearly a honey-jar product.
        if (preg_match('/^\s*(?:(?:raw|drizzle|wildflower|clover|liquid|pure|organic|spring|local)\s+)+honey\s*$/i', $titleLower)) return false;
        if (preg_match('/^\s*honey\s+(?:jar|drizzle|gold|white|amber|comb|stick|sticks|bottle)\s*$/i', $titleLower)) return false;
        // Drizzle Honey specifically — even without a prefix word, this exact
        // 2-word title is honey-product.
        if (preg_match('/^\s*drizzle\s+honey\b/i', $titleLower)) return false;

        // Chocolate CONFECTIONERY — bars, truffles, squares, etc. Matches
        // only "chocolate <product-noun>" so that "Chocolate Cake" / "Choc
        // Cherry Bomb" / "Cookies & Chocolate" coffees pass through as
        // flavor descriptors. The hot-cocoa pattern above + the productType
        // 'chocolate' excludeType handle the remaining real chocolate cases.
        $chocolateProductNouns = 'bars?|truffles?|squares?|tablets?|buttons?|drops?|medallions?|bonbons?|wafers?|chips?|discs?|nibs?';
        if (preg_match('/\bchocolate\s+(?:' . $chocolateProductNouns . ')\b/', $titleLower)) return false;
        // Reverse order: "70% Chocolate Squares" — already caught above.
        // "Dark/Milk/White Chocolate <noun>": catches the prefixed
        // confectionery shape too. ("Dark Chocolate Truffles", "Milk
        // Chocolate Buttons"). Allow "Dark Chocolate" alone-in-name to
        // pass for flavor descriptors like "Dark Chocolate Espresso".
        if (preg_match('/\b(?:dark|milk|white|raw)\s+chocolate\s+(?:' . $chocolateProductNouns . ')\b/', $titleLower)) return false;

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

        // Brew-gear vocabulary in the TITLE. Big stores carry deep hardware
        // catalogs whose product_type we may not have mapped, and whose
        // titles don't contain the word "coffee" so the gift-card/cleaner
        // guards above miss them ("OREA - Tulip Folding Tool", "UFO -
        // Dripper V3", "Comandante - C40 Hand Grinder", "MHW-3BOMBER -
        // Espresso Puck Screen", "Barista Hustle - Cupping Bowls"). These
        // words never appear in a real bag-of-beans product name, so a
        // word-boundary match is safe.
        $brewGearWords = [
            'dripper', 'drippers', 'pour[\s-]?over\s+(?:kit|set|stand|holder)',
            'negotiator', 'wdt\b', 'wdt\s+tool', 'distribution\s+tool', 'puck\s+screen',
            'dosing\s+(?:cup|ring|funnel)', 'dosing\s+cup', 'tamper', 'tampers',
            'portafilter', 'puck', 'cupping\s+(?:bowl|bowls|spoon|spoons|set|kit)',
            'cupper\'?s\s+kit', 'gooseneck', 'decanter', 'french\s+press',
            'aeropress', 'chemex', 'moka\s+pot', 'cold\s+brew\s+(?:maker|tower|kit)',
            'milk\s+pitcher', 'knock\s+box', 'drip\s+stand', 'brew\s+stand',
            'dripper\s+holder', 'dripper\s+stand', 'paper\s+filters?', 'filter\s+papers?',
            'reusable\s+filter', 'metal\s+filter', 'cloth\s+filter', 'abaca',
            'burr\b', 'burrs\b', 'crank\b', 'hand\s+grinder', 'electric\s+grinder',
            'grinder\b', 'grinders\b', 'brew\s+kit', 'brewing\s+kit', 'travel\s+pack',
            'carrying\s+case', 'polymer\s+jar', 'glass\s+jars?', 'vacuum\s+container',
            'sensory\s+(?:cup|glass|flavou?r)', 'flavou?r\s+cup', 'aroma\s+(?:cup|mug)',
            'tasting\s+glass', 'wine\s+glass', 'recipe\s+card', 'menu\b',
            'm\*lk', 'alternative\s+milk',
        ];
        if (preg_match('/\b(' . implode('|', $brewGearWords) . ')/u', $titleLower)) return false;

        // Cascara (dried coffee-cherry husk) is brewed like a tisane, not
        // roasted coffee beans — stores file the standalone product under
        // product_type "Coffee" with a "Tea" tag. BUT "cascara" is also a
        // legit processing/co-ferment descriptor on real coffees
        // ("Ethiopia - Idido Cascara Infused | Washed", "… Cascara
        // Co-Ferment"). So only reject when cascara is clearly the PRODUCT:
        //   - title starts with "Cascara …", or
        //   - "cascara" sits next to cherry/husk/tea/tisane/pulp/skin/dried.
        // A country/region-prefixed title with mid-name "Cascara <process>"
        // is a real bean and falls through.
        if (preg_match('/^\s*cascara\b/', $titleLower)
            || preg_match('/\bcascara\b[\s\-|]*(?:dried|cherry|cherries|husks?|tea|tisane|pulp|skin|infusion)\b/', $titleLower)
            || preg_match('/\b(?:dried|cherry|husks?|tea|tisane)\b[\s\-|]*cascara\b/', $titleLower)) {
            return false;
        }

        // Internal / placeholder / mystery SKUs that aren't a real listed
        // bag of beans: "Secret shop", "Coffee Lab: TEST ROAST",
        // "Roaster's Club", "Surprise - We will choose…".
        if (preg_match('/\b(secret\s+shop|test\s+roast|coffee\s+lab\s*:|roaster\'?s\s+club|staff\s+(?:pick\s+)?test|do\s+not\s+(?:buy|order|use)|placeholder|internal\s+use)\b/', $titleLower)) return false;

        // Gear/merch TAGS. Equipment is tagged with unambiguous gear markers
        // ("New Gear & Equipment", "Espresso Accessories", "Brewer",
        // "SmartrrFilter:Brewers", "Dripper"). Critically this runs BEFORE
        // the positive tag check below — without it, a "SmartrrFilter:Brewers"
        // tag matches the loose "filter" positive marker and a grinder gets
        // imported as coffee. We anchor on whole tag tokens (space-delimited)
        // so a real coffee tagged "Filter" or "Espresso" is unaffected.
        $gearTagPatterns = [
            'gear\s*&?\s*equipment', 'new\s+gear', 'equipment',
            'espresso\s+accessor', 'coffee\s+accessor', 'grinder\s+accessor',
            'brewing\s+equipment', 'barista\s+(?:tool|supplies|accessor)',
        ];
        foreach ($gearTagPatterns as $gp) {
            if (preg_match('/\b' . $gp . '/u', $tagStr)) return false;
        }
        // Exact gear tag tokens (the tag IS this word, not merely contains
        // it). "brewer"/"brewers"/"dripper"/"grinder"/"kettle"/… as a
        // standalone tag = hardware. "smartrrfilter:brewers" is the Smartrr
        // subscription-app's collection tag for the Brewers category.
        $gearExactTags = ['brewer', 'brewers', 'dripper', 'drippers', 'grinder', 'grinders',
                          'kettle', 'kettles', 'scale', 'scales', 'tamper', 'tampers',
                          'server', 'servers', 'serveware', 'drinkware', 'glassware',
                          'merch', 'merchandise', 'apparel', 'sticker', 'stickers',
                          'mug', 'mugs', 'tumbler', 'tumblers', 'spoon', 'spoons',
                          'smartrrfilter:brewers', 'smartrrfilter:grinders',
                          'smartrrfilter:accessories', 'smartrrfilter:drippers'];
        foreach ($tagsLower as $tg) {
            if (in_array($tg, $gearExactTags, true)) return false;
        }

        // Tea / tisane / non-coffee infusions. Their titles often contain
        // "Blend" or other positive markers that sneak past the positive
        // checks below ("Royal Oolong Blend", "Vanilla Rooibos Blend").
        // The product_type 'tea' / 'matcha' exclusion above catches
        // well-typed listings; this title-level guard catches tea sold
        // under generic types like 'Loose Leaf' or no type at all. We do
        // NOT match bare 'tea' here — only unambiguous tea-genus / tisane
        // vocabulary — so a fanciful coffee name can't false-trigger.
        $teaWords = [
            'oolong', 'pu-?erh', 'puer', 'sencha', 'gyokuro', 'genmaicha',
            'hojicha', 'matcha', 'rooibos', 'yerba\s+mate', 'mate\s+tea',
            'chai\s+(?:tea|latte|blend)', 'herbal\s+tea', 'green\s+tea',
            'black\s+tea', 'white\s+tea', 'oolong\s+tea', 'tisane',
            'loose\s+leaf',
        ];
        if (preg_match('/\b(' . implode('|', $teaWords) . ')\b/u', $titleLower)) return false;

        // Positive signals — any of these = coffee.
        // 1) product_type contains a coffee/brew-method keyword
        $coffeeTypeKeywords = ['coffee', 'bean', 'filter', 'espresso', 'drip', 'pour over',
                               'pour-over', 'whole bean', 'wholebean', 'ground', 'omni', 'roast'];
        foreach ($coffeeTypeKeywords as $kw) {
            if ($kw !== '' && str_contains($type, $kw)) return true;
        }
        // 2) tags include a coffee marker (Shopify roasters tag heavily).
        // Whole-token match only — the old loose `str_contains($tagStr,$kw)`
        // fallback let "filter" hit "smartrrfilter:brewers" and "espresso"
        // hit "espresso accessories", importing gear as coffee. The gear-tag
        // guard above already removes the worst offenders; this stays strict.
        $coffeeTagKeywords = ['coffee', 'beans', 'single origin', 'single-origin', 'blend',
                              'espresso', 'filter', 'decaf', 'whole bean', 'roasted coffee',
                              'coffee beans',
                              // Roast-level taxonomy. Some roaster sites (Oso Negro is
                              // the canonical case) tag coffees ONLY by roast level
                              // and origin region — no "Coffee" tag, no "coffee" in
                              // the title. Without these markers, ~15 of Oso Negro's
                              // 17 coffees were silently rejected. Safe because the
                              // earlier excludeTypes / gear-tag / merch checks fire
                              // first, so a "Dark"-coloured hoodie can't slip through.
                              'light', 'medium', 'dark', 'very dark',
                              'light roast', 'medium roast', 'dark roast',
                              // "Full City" is roast-level taxonomy too — Midnight Sun
                              // categorizes its Colombian under a bare "Full City"
                              // category with no other coffee marker in the feed.
                              'full city',
                              // Growing-region taxonomy from the same roaster style.
                              // These are coffee-specific in this context — a merch
                              // product on a roaster's site wouldn't be tagged
                              // "Indonesia" / "Africa" as a primary category.
                              'africa', 'americas', 'asia', 'indonesia',
                              'central america', 'south america'];
        foreach ($coffeeTagKeywords as $kw) {
            if (in_array($kw, $tagsLower, true)) return true;
        }
        // 2b) Compound coffee tags. The exact-token check above misses
        // multi-word tags like "Whole Bean Coffee", "Single Origin Coffee",
        // "Single-Origin Coffee Beans", "Roasted Coffee Beans" — the whole
        // tag string never equals a bare keyword, so a roaster that tags its
        // beans only with a compound phrase AND uses bare-origin product
        // titles ("Ethiopia Yirgacheffe") lost every coffee. This is the
        // controlled re-introduction of the substring fallback that c637b63
        // removed wholesale (it had over-corrected: the goal was to stop
        // "filter" hitting "smartrrfilter:brewers", but switching to exact
        // in_array also dropped legitimate compound coffee tags).
        //
        // Safe because: (a) every gear/merch/accessory negative check has
        // already run and returned false above — including the exact
        // gear-tag tokens and the "espresso/coffee/grinder accessor" tag
        // patterns; (b) we anchor on word boundaries, so "filter" can't hit
        // "smartrrfilter:brewers" (no boundary mid-concatenation) the way
        // the old bare str_contains did. We deliberately match only NOUNS
        // that, standing alone in a roaster's product tag, unambiguously
        // mean "this is coffee" — bare brew-method words (filter, espresso,
        // drip) are excluded because those are exactly the ones that
        // collided with gear-accessory tags.
        // Qualified "<kind> Blends" category names ("Signature Blends",
        // "House Blends", "Espresso Blends") — Midnight Sun's house coffees
        // carry ONLY a "Signature Blends" category, which neither the exact
        // 'blend' token above nor the noun regex below matched. Deliberately
        // NOT a bare `blends?` — that would let a "Tea Blends" / "Herbal
        // Blends" tag through; the qualifier keeps it unambiguous coffee.
        if (preg_match('/\b(coffees?|decaf|single[\s-]origin|whole[\s-]bean|roasted\s+(?:coffee|beans?)|(?:signature|house|coffee|espresso|seasonal)\s+blends?)\b/u', $tagStr)) {
            return true;
        }
        // 3) title literally mentions coffee/blend/espresso/etc.
        if (preg_match('/\b(coffee|espresso|blend|decaf|single[- ]origin)\b/', $titleLower)) return true;

        // 3b) Origin-named coffee. Specialty roasters routinely title coffees
        // purely by farm / region / country with no "coffee" word and no
        // coffee tag — "Bohemia (Washed Gesha), Colombia", "Gatugi, Kenya".
        // Those fall through to `return false` whenever the product carries a
        // NON-coffee category (e.g. Squarespace's "Top Tier" quality tier),
        // because a non-empty tag set suppresses the no-tags default-accept
        // below. Every gear / tea / merch / cleaner / gift-card negative has
        // already returned false above, so a recognizable coffee-origin
        // country in the title is a safe positive — and the downstream
        // parseGrams() gate still requires a real bag size, so a stray
        // country word alone can't import junk.
        if (OriginGazetteer::inferCountry($title) !== '') return true;

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

    /**
     * Scrub a string of invalid UTF-8 byte sequences. Scraped product
     * feeds sometimes return text in Latin-1 / Windows-1252 / mixed
     * encodings; storing those raw bytes is fine for SQLite but blows
     * up `json_encode` later with "Malformed UTF-8 characters" → API
     * 500. Round-tripping through `mb_convert_encoding('UTF-8','UTF-8')`
     * replaces invalid sequences with U+FFFD without rejecting valid
     * UTF-8 (it's a no-op on clean input — `Café Saint-Henri` stays
     * `Café Saint-Henri`).
     *
     * Apply at write-time in the importer so the DB never grows new
     * bad bytes. The API also defensively passes JSON_INVALID_UTF8_
     * SUBSTITUTE so any historical bad rows render instead of 500.
     */
    public static function sanitizeUtf8(?string $s): ?string
    {
        if ($s === null) return null;
        return mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }
}
