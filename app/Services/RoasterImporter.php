<?php

namespace App\Services;

use App\Models\Roaster;
use App\Models\ScraperRejectionLog;
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
     * On failure it records roasters.last_import_status='error' +
     * last_import_error (so the admin index surfaces it) AND re-throws — the
     * caller (admin action / queued job / cron command) decides how to report.
     */
    public function import(string $url, ?string $name = null, ?string $city = null, ?string $region = null): Roaster
    {
        $name ??= CoffeeTextNormalizer::inferNameFromUrl($url);
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
            // Stale-cache recovery: a CACHED platform that now hard-errors
            // (e.g. Shopify /products.json 404 after the roaster migrated to
            // Squarespace) is a strong signal the cache is wrong. Try a fresh
            // re-detect before recording failure; adopt it only if a
            // different, more-specific platform actually returns coffees.
            $recovered = $roaster->platform
                ? $this->attemptRedetect($roaster, $website, $roaster->platform)
                : null;
            if ($recovered === null) {
                $roaster->forceFill([
                    'last_imported_at' => Carbon::now(),
                    'last_import_status' => 'error',
                    'last_import_error' => $e->getMessage(),
                ])->save();
                throw $e;
            }
            [$scraper, $coffees] = $recovered;
        }

        // Self-healing platform re-detection: a cached platform that fetched
        // EMPTY (no error, just nothing) can also be stale — Prototype was
        // cached as 'generic' but is really Squarespace, so every import
        // returned zero coffees. Re-probe fresh and switch if a more-specific
        // platform yields a real catalog. Skipped on first import (no cache),
        // where detect() already probed fresh.
        if (empty($coffees) && $roaster->platform) {
            $recovered = $this->attemptRedetect($roaster, $website, $roaster->platform);
            if ($recovered !== null) {
                [$scraper, $coffees] = $recovered;
            }
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
                $roaster->shipping_notes = Shared::sanitizeUtf8($sp['shipping_notes']);
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
     * Re-probe the platform from scratch and adopt it ONLY if a different,
     * more-specific platform actually returns a non-empty catalog. Used to
     * self-heal a stale roasters.platform cache (e.g. a roaster migrated
     * Shopify→Squarespace, or was seeded as 'generic' before a dedicated
     * scraper could handle it).
     *
     * Returns [$scraper, $coffees] on a successful heal, or null to leave the
     * cached platform untouched. We deliberately refuse to heal toward
     * 'generic': GenericHtmlScraper::canHandle() always returns true, so a
     * fresh detect falling through to 'generic' just means "no dedicated
     * platform matched right now" — adopting it during a transient
     * Shopify/Woo outage would wrongly erase a good cached platform.
     *
     * @return array{0: \App\Services\Scraping\RoasterScraper, 1: array}|null
     */
    private function attemptRedetect(Roaster $roaster, string $website, string $currentPlatform): ?array
    {
        try {
            $fresh = $this->registry->detect($website, null);
        } catch (\Throwable) {
            return null;
        }

        $freshKey = $fresh->platformKey();
        if ($freshKey === $currentPlatform || $freshKey === 'generic') {
            return null;
        }

        try {
            $coffees = $fresh->fetch($website);
        } catch (\Throwable) {
            return null;
        }
        if (empty($coffees)) {
            return null;
        }

        $roaster->platform = $freshKey;
        return [$fresh, $coffees];
    }

    /**
     * Reconcile this roaster's coffees with the freshly-scraped set.
     *
     * Q1+Q2 invariant: user tastings/wishlists (FK on coffee_id) must survive
     * a re-import. So:
     *  - Match each scraped coffee to an existing row by (roaster_id, source_id).
     *  - If that misses, fall back to a case-insensitive (roaster_id, name)
     *    match against legacy rows whose source_id is NULL (older code paths
     *    didn't populate it). Re-binding lets the fresh import backfill
     *    source_id onto the existing row instead of creating a duplicate.
     *  - If found: update it in place; clear removed_at if previously soft-removed.
     *  - If not found: create a new row.
     *  - Coffees that existed before this run but didn't appear in the fetch
     *    (matched by neither source_id nor name): set removed_at to the
     *    current timestamp (soft-remove). The row stays; foreign keys hold.
     *
     * Variants don't have user FKs (tastings link to the coffee, not the
     * variant), so they keep the simpler delete-and-recreate per coffee.
     */
    private function syncCoffees(Roaster $roaster, array $coffees): void
    {
        $now = Carbon::now();
        // ALL existing coffees (including NULL source_id) keyed by id so we
        // can track which rows the run touched and soft-remove the rest.
        $existing = $roaster->coffees()->get()->keyBy('id');
        $touchedIds = [];

        // SAFETY: if the scraper returned nothing AND the roaster already has
        // a catalog, do nothing. An empty fetch is almost always a transient
        // failure (DNS blip, rate limit, platform-shape shift, password-
        // protected suddenly) and we should NOT wipe an entire roaster's
        // catalog over one bad poll. The importer's `last_import_status`
        // already records 'empty' so the admin surface flags this for
        // investigation; meanwhile users keep seeing the inventory.
        if (empty($coffees) && $existing->isNotEmpty()) {
            return;
        }

        // Trust#9: rejection logs are a snapshot of the LATEST import, not a
        // growing daily-cron history. Clear this roaster's prior rows here —
        // after the empty-fetch guard so a transient empty poll doesn't wipe
        // the breadcrumbs — then syncVariants re-logs whatever it drops below.
        ScraperRejectionLog::where('roaster_id', $roaster->id)->delete();

        foreach ($coffees as $c) {
            $sourceId = (string) ($c['source_id'] ?? '');
            $name = (string) ($c['name'] ?? '');

            // 1) source_id match (fast path, stable across renames).
            $row = $sourceId !== ''
                ? $existing->first(fn ($r) => (string) $r->source_id === $sourceId)
                : null;

            // 2) Name fallback for legacy NULL-source_id rows. Case-insensitive
            //    so import-time title-case fixups don't fork the row. Skip
            //    rows already claimed in this run by another scraped coffee.
            if (!$row && $name !== '') {
                $row = $existing->first(fn ($r) =>
                    $r->source_id === null
                    && !isset($touchedIds[$r->id])
                    && strcasecmp((string) $r->name, $name) === 0
                );
            }

            $upserted = $this->upsertCoffee($roaster, $c, $row);
            $touchedIds[$upserted->id] = true;
        }

        // Soft-remove anything that existed before this run but wasn't
        // touched — covers both vanished source_id rows AND legacy
        // NULL-source_id rows whose name no longer matches any scraped
        // product (the Costa Rican Tarrazú class of stale entry).
        foreach ($existing as $coffee) {
            if (isset($touchedIds[$coffee->id])) continue;
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
        $extractedNotes = CoffeeTextNormalizer::extractTastingNotes($rawDescription)
            ?? CoffeeFieldExtractor::extractTastingNotes($rawDescription);
        $extractedVarietal = CoffeeFieldExtractor::extractVarietal($rawDescription)
            ?? CoffeeFieldExtractor::extractVarietal($c['name'] ?? '');
        $extractedProcess = CoffeeFieldExtractor::extractProcess($rawDescription)
            ?? CoffeeFieldExtractor::extractProcess($c['name'] ?? '');
        $extractedRoast = CoffeeFieldExtractor::extractRoastLevel($rawDescription)
            ?? CoffeeFieldExtractor::extractRoastLevel($c['name'] ?? '');
        $extractedElevation = CoffeeFieldExtractor::extractElevation($rawDescription);

        // NOW clean for storage/display.
        $description = CoffeeTextNormalizer::cleanDescription($rawDescription);

        // Normalize an empty platform id to NULL. Storing '' lets a second
        // id-less product on the same roaster collide on the
        // (roaster_id, source_id) UNIQUE index and abort the entire sync;
        // NULLs are distinct in that index, so id-less products coexist and
        // route through the name-fallback match instead.
        $sourceId = $c['source_id'] ?? null;
        if ($sourceId === '') {
            $sourceId = null;
        }

        $payload = [
            'source_id' => $sourceId,
            'name' => CoffeeTextNormalizer::cleanCoffeeName($c['name']),
            'origin' => CoffeeTextNormalizer::inferOrigin($c['name']),
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

        // Sanitize every user-visible string field. Audits on the live API
        // surfaced 43 fields with leading/trailing whitespace, 31 with
        // multi-spaces, 15 with literal "&amp;", 9 with curly apostrophes —
        // all of which sanitizeText fixes in one pass. Skipped: description
        // (already runs through cleanDescription), URLs, source_id, removed_at.
        $textFields = ['tasting_notes', 'process', 'varietal', 'roast_level', 'origin'];
        foreach ($textFields as $f) {
            if (is_string($payload[$f] ?? null) && $payload[$f] !== '') {
                $sanitized = CoffeeTextNormalizer::sanitizeText($payload[$f]);
                $payload[$f] = $sanitized === '' ? null : $sanitized;
            }
        }

        // Scrub bad bytes out of every string field — scraped product
        // feeds occasionally return Latin-1 / Windows-1252 / mixed
        // encodings whose raw bytes break json_encode at read time.
        // No-op on clean UTF-8; replaces invalid sequences with U+FFFD.
        $payload = array_map(
            fn ($v) => is_string($v) ? Shared::sanitizeUtf8($v) : $v,
            $payload
        );

        if ($existing) {
            $existing->fill($payload)->save();
            $coffee = $existing;
        } else {
            $coffee = $roaster->coffees()->create($payload);
        }

        $this->syncVariants($coffee, $c['variants'] ?? [], $c['product_url'] ?? $roaster->website);

        // Keep the indexed best_cents_per_gram (used for DB-side sort/filter on
        // /api/coffees) in lockstep with the freshly-synced variant prices.
        $coffee->load('variants');
        $coffee->refreshBestCentsPerGram();

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
            if ($price <= 0) {
                // defense in depth — never persist $0 variants; log so a feed
                // that suddenly reports every price as 0 is visible (Trust#9).
                $this->logRejection($coffee, ScraperRejectionLog::REASON_PRICE_NON_POSITIVE, [
                    'price' => $v['price'] ?? null,
                    'grams' => $grams,
                    'source_size_label' => $v['source_size_label'] ?? null,
                ]);
                continue;
            }
            // Sanity check on $/g — Canadian specialty coffee runs roughly
            // 3.5¢/g (cheapest commodity) to 200¢/g (rare Geisha lots).
            // Anything outside this band is almost certainly a parsing bug:
            // sub-3¢/g usually means a misread bag size (the "3/4lb → 4lb"
            // class of bug), and >200¢/g means a portion pack or sample.
            if ($grams > 0) {
                $cpg = ($price / $grams) * 100;
                if ($cpg < 2.5 || $cpg > 250) {
                    $this->logRejection($coffee, ScraperRejectionLog::REASON_CPG_OUT_OF_BAND, [
                        'price' => $v['price'] ?? null,
                        'grams' => $grams,
                        'cpg' => round($cpg, 1),
                        'source_size_label' => $v['source_size_label'] ?? null,
                    ]);
                    continue;
                }
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

    /**
     * Trust#9: record one dropped variant. Best-effort — telemetry must never
     * break an import, so a write failure here is swallowed. coffee_name is
     * snapshotted alongside the FK so the log reads cleanly even if the coffee
     * is later removed or renamed.
     */
    private function logRejection(\App\Models\Coffee $coffee, string $reason, array $context): void
    {
        try {
            ScraperRejectionLog::create([
                'roaster_id' => $coffee->roaster_id,
                'coffee_id' => $coffee->id,
                'coffee_name' => $coffee->name,
                'reason' => $reason,
                'context' => $context,
            ]);
        } catch (\Throwable) {
            // never let a telemetry write abort the catalog sync
        }
    }

}
