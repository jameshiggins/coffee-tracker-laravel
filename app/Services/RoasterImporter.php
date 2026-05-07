<?php

namespace App\Services;

use App\Models\Roaster;
use App\Services\CoffeeFieldExtractor;
use App\Services\FrenchToEnglish;
use App\Services\OriginGazetteer;
use App\Services\Scraping\AboutPageScraper;
use App\Services\Scraping\FaviconScraper;
use App\Services\Scraping\ScraperRegistry;
use App\Services\Scraping\Shared;
use App\Services\Scraping\ShippingPolicyScraper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RoasterImporter
{
    private ScraperRegistry $registry;
    private AboutPageScraper $about;
    private ShippingPolicyScraper $shipping;
    private FaviconScraper $favicon;

    public function __construct(
        ?ScraperRegistry $registry = null,
        ?AboutPageScraper $about = null,
        ?ShippingPolicyScraper $shipping = null,
        ?FaviconScraper $favicon = null
    ) {
        $this->registry = $registry ?? new ScraperRegistry();
        $this->about = $about ?? new AboutPageScraper();
        $this->shipping = $shipping ?? new ShippingPolicyScraper();
        $this->favicon = $favicon ?? new FaviconScraper();
    }

    /**
     * Import (or refresh) a roaster from any supported platform URL.
     * Detects platform on first run via ScraperRegistry, caches the result
     * on roasters.platform, and dispatches directly on subsequent runs.
     *
     * Failures get recorded on roasters.last_import_status / last_import_error
     * but don't throw — admin index surfaces them.
     */
    public function import(string $url, ?string $name = null, ?string $city = null, ?string $region = null): Roaster
    {
        $name ??= $this->inferNameFromUrl($url);
        $slug = Str::slug($name);
        $website = Shared::origin($url);

        // Match by slug first (cheap exact match). If that misses, match by
        // website to handle rebrands/renames where the slug shifts but the
        // URL is stable. Without the fallback, a rename creates a duplicate.
        $roaster = Roaster::where('slug', $slug)->first()
            ?: Roaster::where('website', $website)->first();

        if ($roaster) {
            // Only push the new slug if no OTHER roaster already owns it
            // (defensive: avoids the unique-constraint crash if the user
            // already manually created a row with the new slug).
            $newSlug = Roaster::where('slug', $slug)->where('id', '!=', $roaster->id)->exists()
                ? $roaster->slug
                : $slug;

            $roaster->fill([
                'name' => $name,
                'slug' => $newSlug,
                'city' => $city ?? $roaster->city ?? 'Unknown',
                'region' => $region ?? $roaster->region,
                'website' => $website,
                'is_active' => true,
            ])->save();
        } else {
            $roaster = Roaster::create([
                'slug' => $slug,
                'name' => $name,
                'city' => $city ?? 'Unknown',
                'region' => $region,
                'website' => $website,
                'has_shipping' => true,
                'is_active' => true,
            ]);
        }

        try {
            $scraper = $this->registry->detect($website, $roaster->platform);
            $coffees = $scraper->fetch($website);
        } catch (\Throwable $e) {
            $roaster->forceFill([
                'last_imported_at' => Carbon::now(),
                'last_import_status' => 'error',
                'last_import_error' => $e->getMessage(),
            ])->save();
            throw $e;
        }

        // Persist the detected platform on first successful fetch.
        if (!$roaster->platform) {
            $roaster->platform = $scraper->platformKey();
        }

        // Backfill description from the about/homepage og:description ONLY when
        // there's no admin override. Best-effort; failure here doesn't block.
        if (!$roaster->description) {
            try {
                $blurb = $this->about->fetch($website);
                if ($blurb) {
                    $roaster->description = $blurb;
                }
            } catch (\Throwable) {
                // ignore — about-page scraping is best-effort
            }
        }

        // Backfill favicon when missing. Cheap one-shot HTML scrape;
        // null result falls through to Google's S2 service at API render time.
        if (!$roaster->favicon_url) {
            try {
                $fav = $this->favicon->fetch($website);
                if ($fav) {
                    $roaster->favicon_url = $fav;
                }
            } catch (\Throwable) {
                // best-effort — never block the import on icon scraping
            }
        }

        // Backfill shipping policy when missing. Heuristic regex over the
        // /policies/shipping-policy or /shipping page. Admin can override
        // anything — we never overwrite existing non-null values.
        try {
            $sp = $this->shipping->fetch($website);
            if ($roaster->shipping_cost === null && $sp['shipping_cost'] !== null) {
                $roaster->shipping_cost = $sp['shipping_cost'];
            }
            if ($roaster->free_shipping_over === null && $sp['free_shipping_over'] !== null) {
                $roaster->free_shipping_over = $sp['free_shipping_over'];
            }
            if (!$roaster->shipping_notes && $sp['shipping_notes']) {
                $roaster->shipping_notes = self::sanitizeUtf8($sp['shipping_notes']);
            }
        } catch (\Throwable) {
            // shipping scrape is best-effort
        }

        $this->syncCoffees($roaster, $coffees);

        $roaster->forceFill([
            'last_imported_at' => Carbon::now(),
            'last_import_status' => empty($coffees) ? 'empty' : 'success',
            'last_import_error' => null,
        ])->save();

        return $roaster->fresh('coffees.variants');
    }

    /**
     * Reconcile this roaster's coffees with the freshly-scraped set.
     *
     * Q1+Q2 invariant: user tastings/wishlists (FK on coffee_id) must survive
     * a re-import. So:
     *  - Match each scraped coffee to an existing row by (roaster_id, source_id).
     *  - If found: update it in place; clear removed_at if previously soft-removed.
     *  - If not found: create a new row.
     *  - Coffees that existed before this run but didn't appear in the fetch:
     *    set removed_at to the current timestamp (soft-remove). The row stays;
     *    foreign keys hold.
     *
     * Variants don't have user FKs (tastings link to the coffee, not the
     * variant), so they keep the simpler delete-and-recreate per coffee.
     */
    private function syncCoffees(Roaster $roaster, array $coffees): void
    {
        $now = Carbon::now();
        $existingBySourceId = $roaster->coffees()->whereNotNull('source_id')->get()->keyBy('source_id');
        $seenSourceIds = [];

        foreach ($coffees as $c) {
            $sourceId = (string) ($c['source_id'] ?? '');
            if ($sourceId === '') {
                // Scraper didn't expose a stable id (rare — generic-html with no
                // schema URL). Fall back to creating a fresh row each time;
                // matches old behaviour for that edge case.
                $this->upsertCoffee($roaster, $c, null);
                continue;
            }
            $seenSourceIds[$sourceId] = true;
            $existing = $existingBySourceId->get($sourceId);
            $this->upsertCoffee($roaster, $c, $existing);
        }

        // Soft-remove any previously-imported coffee not seen in this run.
        $missing = $existingBySourceId->reject(fn ($coffee, $sid) => isset($seenSourceIds[$sid]));
        foreach ($missing as $coffee) {
            if ($coffee->removed_at === null) {
                $coffee->forceFill(['removed_at' => $now])->save();
            }
        }
    }

    private function upsertCoffee(Roaster $roaster, array $c, ?\App\Models\Coffee $existing): \App\Models\Coffee
    {
        // Translate French → English up front so all downstream
        // extraction + cleaning sees a uniform English string.
        $rawDescription = FrenchToEnglish::translate((string) ($c['description'] ?? ''));
        if (isset($c['name'])) $c['name'] = FrenchToEnglish::translate($c['name']);
        if (isset($c['tasting_notes'])) $c['tasting_notes'] = FrenchToEnglish::translate($c['tasting_notes']);

        // Run extractors against the RAW description first — cleanDescription
        // strips labelled blocks ("Tasting Notes:", "Process:", etc.) and
        // running the extractors on the cleaned output would miss everything.
        $extractedNotes = $this->extractTastingNotes($rawDescription)
            ?? CoffeeFieldExtractor::extractTastingNotes($rawDescription);
        $extractedVarietal = CoffeeFieldExtractor::extractVarietal($rawDescription)
            ?? CoffeeFieldExtractor::extractVarietal($c['name'] ?? '');
        $extractedProcess = CoffeeFieldExtractor::extractProcess($rawDescription)
            ?? CoffeeFieldExtractor::extractProcess($c['name'] ?? '');
        $extractedRoast = CoffeeFieldExtractor::extractRoastLevel($rawDescription)
            ?? CoffeeFieldExtractor::extractRoastLevel($c['name'] ?? '');
        $extractedElevation = CoffeeFieldExtractor::extractElevation($rawDescription);

        // NOW clean for storage/display.
        $description = $this->cleanDescription($rawDescription);

        $payload = [
            'source_id' => $c['source_id'] ?? null,
            'name' => $this->cleanCoffeeName($c['name']),
            'origin' => $this->inferOrigin($c['name']),
            'description' => $description,
            'tasting_notes' => ($c['tasting_notes'] ?? null) ?: $extractedNotes,
            'process' => ($c['process'] ?? null) ?: $extractedProcess,
            'varietal' => ($c['varietal'] ?? null) ?: $extractedVarietal,
            'roast_level' => ($c['roast_level'] ?? null) ?: $extractedRoast,
            'elevation_meters' => $extractedElevation,
            'product_url' => $c['product_url'] ?? null,
            'image_url' => $c['image_url'] ?? null,
            'is_blend' => $c['is_blend'] ?? false,
            'removed_at' => null, // un-remove if it had been soft-removed
        ];

        if ($existing) {
            $existing->fill($payload)->save();
            $coffee = $existing;
        } else {
            $coffee = $roaster->coffees()->create($payload);
        }

        $this->syncVariants($coffee, $c['variants'] ?? [], $c['product_url'] ?? $roaster->website);
        return $coffee;
    }

    /**
     * Upsert variants by (coffee_id, bag_weight_grams). Tracks in_stock
     * transitions on in_stock_changed_at so Q14's restock-alerts cron
     * can find OOS→in-stock deltas.
     *
     * Variants that no longer appear in the import are deleted (no FK
     * from anywhere else points at variants — tastings link to coffees).
     */
    private function syncVariants(\App\Models\Coffee $coffee, array $scrapedVariants, ?string $purchaseLink): void
    {
        $existing = $coffee->variants()->get()->keyBy('bag_weight_grams');
        $now = Carbon::now();
        $seen = [];

        foreach ($scrapedVariants as $v) {
            $grams = $v['grams'];
            $price = (float) ($v['price'] ?? 0);
            if ($price <= 0) continue;  // defense in depth — never persist $0 variants
            // Sanity check on $/g — Canadian specialty coffee runs roughly
            // 3.5¢/g (cheapest commodity) to 200¢/g (rare Geisha lots).
            // Anything outside this band is almost certainly a parsing bug:
            // sub-3¢/g usually means a misread bag size (the "3/4lb → 4lb"
            // class of bug), and >200¢/g means a portion pack or sample.
            if ($grams > 0) {
                $cpg = ($price / $grams) * 100;
                if ($cpg < 2.5 || $cpg > 250) continue;
            }
            $newInStock = (bool) ($v['available'] ?? true);
            $seen[$grams] = true;
            // Per-variant link from the scraper (Shopify ?variant=<id> pattern)
            // takes precedence; fall back to the coffee-level URL otherwise.
            $variantLink = $v['purchase_link'] ?? $purchaseLink;

            $row = $existing->get($grams);
            if ($row) {
                $stockTransitioned = $row->in_stock !== $newInStock;
                $row->fill([
                    'price' => $v['price'],
                    'in_stock' => $newInStock,
                    'purchase_link' => $variantLink,
                    'source_size_label' => $v['source_size_label'] ?? null,
                ]);
                if ($stockTransitioned) {
                    $row->in_stock_changed_at = $now;
                }
                $row->save();
            } else {
                $coffee->variants()->create([
                    'bag_weight_grams' => $grams,
                    'source_size_label' => $v['source_size_label'] ?? null,
                    'price' => $v['price'],
                    'in_stock' => $newInStock,
                    'in_stock_changed_at' => $now,  // first-seen counts as a transition
                    'purchase_link' => $variantLink,
                ]);
            }
        }

        // Variants that vanished from the import — drop them.
        $existing->reject(fn ($v) => isset($seen[$v->bag_weight_grams]))
            ->each(fn ($v) => $v->delete());
    }

    private function inferNameFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $host = preg_replace('/^(www|shop)\./', '', $host);
        $base = explode('.', $host)[0];
        return ucwords(str_replace(['-', '_'], ' ', $base));
    }

    /**
     * Strip trailing bag-weight annotations from a product title.
     * The directory shows the bag weight separately on each variant row,
     * so "Brazil Santos (454 g)" → "Brazil Santos" reads cleaner.
     * Handles: " (454 g)", "(8 oz)", "- 12oz", " 1lb", " 1 kg", "454g"
     * at the end of the title. Conservative — won't strip mid-title sizes
     * (e.g. "12oz Lined Bag" stays as-is because that's a product name).
     */
    private function cleanCoffeeName(string $name): string
    {
        // Trailing bag-weight annotations.
        $patterns = [
            '/\s*[\(\[]\s*\d+(?:[.,]\d+)?\s*(?:g|gram|grams|kg|kilo|kilos|oz|ounce|ounces|lb|lbs|pound|pounds)\.?\s*[\)\]]\s*$/i',
            '/\s*[-–—|·]\s*\d+(?:[.,]\d+)?\s*(?:g|gram|grams|kg|kilo|kilos|oz|ounce|ounces|lb|lbs|pound|pounds)\.?\s*$/i',
            '/\s+\d+(?:[.,]\d+)?\s*(?:g|gram|grams|kg|kilo|kilos|oz|ounce|ounces|lb|lbs|pound|pounds)\s*$/i',
        ];
        $cleaned = $name;
        foreach ($patterns as $p) {
            $cleaned = preg_replace($p, '', $cleaned) ?? $cleaned;
        }

        // Trailing process-method tags after a separator. Strips
        // "| Washed", "- Natural Process", " (Anaerobic)", etc. We keep
        // process names that aren't preceded by a separator (so "Washed
        // Coffee" the standalone product name stays intact), and only
        // strip from the END so "Anaerobic Lot 12" mid-title is safe.
        $processWords = 'fully\s+washed|double\s+washed|washed\s+process|natural\s+process|dry\s+process|sun\s+dried|pulped\s+natural|wet\s+hulled|giling\s+basah|semi[\s-]washed|carbonic\s+maceration|anaerobic\s+natural|anaerobic\s+washed|anaerobic\s+honey|honey\s+process|honey-?processed|white\s+honey|yellow\s+honey|red\s+honey|black\s+honey|washed|natural|honey|anaerobic|carbonic';
        // Roast levels get their own chip, so strip them too — same rules.
        $roastWords = 'medium[\s-]dark\s+roast|medium[\s-]light\s+roast|extra\s+dark\s+roast|light\s+roast|medium\s+roast|dark\s+roast|city\s+roast|full\s+city\s+roast|french\s+roast|italian\s+roast|vienna\s+roast|filter\s+roast|espresso\s+roast|omni\s+roast|light|medium|dark';
        $processPatterns = [
            // "Peru Marshell | Washed", "Brazil - Natural", "Foundry - Light Roast"
            '/\s*[|·\-–—,]+\s*(?:' . $processWords . ')\s*$/i',
            '/\s*[|·\-–—,]+\s*(?:' . $roastWords . ')\s*$/i',
            // "(Washed)" / "[Light Roast]" trailing
            '/\s*[\(\[]\s*(?:' . $processWords . ')\s*[\)\]]\s*$/i',
            '/\s*[\(\[]\s*(?:' . $roastWords . ')\s*[\)\]]\s*$/i',
        ];
        // Run twice — sometimes a title has both ("- Light Roast - Washed")
        for ($i = 0; $i < 2; $i++) {
            foreach ($processPatterns as $p) {
                $cleaned = preg_replace($p, '', $cleaned) ?? $cleaned;
            }
        }

        $cleaned = trim($cleaned, " \t-–—|·,");
        return $cleaned !== '' ? $cleaned : $name;  // never return empty
    }

    private function cleanDescription(string $raw): ?string
    {
        // Some roaster sites serve copy-pasted Word/Mac smart quotes that
        // arrive as truncated multi-byte sequences. Strip non-UTF-8 bytes
        // first — leaving them in the DB is fine until the API tries to
        // json_encode the row, then it 500s the whole endpoint.
        $s = self::sanitizeUtf8($raw);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Normalize quote/dash typography so split-sentence regex behaves.
        $s = strtr($s, [
            "\u{2018}" => "'", "\u{2019}" => "'", "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2013}" => '-', "\u{2014}" => '-', "\u{2026}" => '...', "\u{00A0}" => ' ',
        ]);
        $s = preg_replace("/\r\n?/", "\n", $s);
        $s = preg_replace('/[ \t]+/', ' ', $s);
        $s = trim($s);
        if ($s === '') return null;

        // Strip everything from the first "brewing recipe / instructions /
        // recommendations" header onwards. Roasters love appending a brew
        // recipe table — irrelevant on a directory card, and it bloats text.
        $cutPattern = '/\b(brewing\s+(?:recipe|recommendations?|instructions?|guide|tips|notes?|method)|brew(?:ing)?\s+(?:guide|method|technique|temperature|temp|time)|how\s+to\s+brew|brew\s+ratio|brew\s+method|recipe[s]?:|grind\s+(?:size|setting|level)|water\s+temperature|water\s+to\s+coffee|coffee\s+to\s+water|extraction\s+time|bloom\s+time|pour[\s-]?over\s+recipe|espresso\s+recipe|aeropress\s+recipe|french\s+press\s+recipe|shipping\s+(?:info|details?)|free\s+shipping|please\s+note|click\s+here|buy\s+now|add\s+to\s+cart|enjoy[\s!]+|cheers!?\s*$)\b/i';
        if (preg_match($cutPattern, $s, $m, PREG_OFFSET_CAPTURE)) {
            $s = trim(substr($s, 0, $m[0][1]));
        }

        // Strip explicit ratios/temps that bleed in mid-sentence:
        // "1:16 ratio", "94°C", "60g/L", "20s bloom"
        $s = preg_replace('/\b\d+\s*:\s*\d+\s*(?:ratio|brew)?\b/i', '', $s);
        $s = preg_replace('/\b\d+\s*°\s*[CF]\b/i', '', $s);
        $s = preg_replace('/\b\d+\s*g\s*\/\s*\d+\s*(?:ml|g|l)\b/i', '', $s);

        // Strip ALL-CAPS section headers ("ABOUT THIS COFFEE", "FROM THE
        // ROASTER:") that some sites prepend to every product.
        $s = preg_replace('/\b[A-Z][A-Z\s]{6,}[A-Z](?:\s*[:\-]|\s*\n)/', '', $s);

        // Strip leading section labels like "Description", "About", "Story",
        // "Overview" — these are leftover <h2> text from strip_tags() runs.
        // Match at the very start, with optional trailing colon/dash/newline.
        $s = preg_replace(
            '/^\s*(?:description|about(?:\s+this\s+(?:coffee|bean|blend))?|overview|story|the\s+story|background|details?|tasting|notes?\s+from\s+(?:the\s+)?roaster|from\s+(?:the\s+)?roaster|product\s+description|product\s+details)\s*[:\-—]?\s+/i',
            '',
            $s
        );

        // Strip explicit "Tasting Notes:" / "Origin:" / "Process:" / etc.
        // labelled blocks because we display those structured fields
        // separately on the card. Keep just the marketing prose.
        $labels = 'tasting\s+notes?|flavou?r\s+notes?|cup\s+notes?|notes?|origin|region|country|process(?:ing)?|varietal|variety|altitude|elevation|producer|farm|roast(?:\s+level)?|harvest|crop\s+year|importer|exporter|grade|score|sca\s+score|cupping\s+score';
        $s = preg_replace(
            '/(?:^|\n|\.\s+|—\s+)\s*(?:' . $labels . ')\s*[:\-]\s*[^\n.]{0,200}(?=\n|\.\s+|$)/iu',
            ' ',
            $s
        );

        // Strip URL fragments and bare email addresses.
        $s = preg_replace('/https?:\/\/\S+/', '', $s);
        $s = preg_replace('/\S+@\S+\.\S+/', '', $s);

        // Drop bullet/list markers — descriptions read better as prose.
        $s = preg_replace('/^[\s•·\-*]+/m', '', $s);

        // Collapse whitespace again after stripping.
        $s = preg_replace('/\n{2,}/', ' ', $s);
        $s = preg_replace('/\s{2,}/', ' ', $s);
        $s = preg_replace('/\s+([,.;:!?])/', '$1', $s);  // no space before punctuation
        $s = trim($s, " \t\n.,;:-");
        if ($s === '') return null;

        // Cap at ~3 sentences or 320 chars, whichever comes first. Capping
        // at the sentence boundary keeps the cut clean (no mid-word ellipsis).
        if (mb_strlen($s) > 320) {
            $sentences = preg_split('/(?<=[.!?])\s+/', $s, 6);
            $kept = '';
            foreach ($sentences as $sent) {
                $candidate = trim($kept . ' ' . $sent);
                if (mb_strlen($candidate) > 320 && $kept !== '') break;
                $kept = $candidate;
            }
            $s = $kept !== '' ? $kept : mb_substr($s, 0, 300) . '…';
        }

        // Sentence-case the first character so "OUR special blend…" → "Our
        // special blend…" without disturbing later capitalization.
        if (mb_strlen($s) > 0 && ctype_upper(mb_substr($s, 0, 1)) && ctype_upper(mb_substr($s, 0, 6))) {
            $s = mb_strtoupper(mb_substr($s, 0, 1)) . mb_strtolower(mb_substr($s, 1));
            // Re-capitalize after periods to restore proper sentences.
            $s = preg_replace_callback('/([.!?]\s+)(.)/u', fn ($m) => $m[1] . mb_strtoupper($m[2]), $s);
            $s = preg_replace_callback('/^(.)/', fn ($m) => mb_strtoupper($m[1]), $s);
        }

        // Ensure a sentence-ending punctuation (helps when we cut mid-blurb).
        if (!preg_match('/[.!?…]$/', $s)) $s .= '.';

        return $s !== '' ? $s : null;
    }

    /** Drop or substitute any byte sequence that isn't valid UTF-8. */
    public static function sanitizeUtf8(string $raw): string
    {
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }
        // mb_convert_encoding with same source+target charset replaces
        // invalid byte sequences with U+FFFD (or '?' depending on PHP build).
        return mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
    }

    private function extractTastingNotes(?string $description): ?string
    {
        if (!$description) return null;
        if (preg_match('/(?:tasting\s+notes?|flavou?r\s+notes?|notes?)\s*[:\-—]\s*([^\n.]{3,120})/i', $description, $m)) {
            // Belt-and-braces: cleanDescription already sanitized $description,
            // but the regex slice could still produce a partial multi-byte
            // sequence at the boundaries.
            return trim(self::sanitizeUtf8($m[1]));
        }
        return null;
    }

    private function inferOrigin(string $title): string
    {
        return OriginGazetteer::inferCountry($title);
    }
}
