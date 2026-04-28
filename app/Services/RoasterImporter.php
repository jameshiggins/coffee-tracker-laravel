<?php

namespace App\Services;

use App\Models\Roaster;
use App\Services\Scraping\ScraperRegistry;
use App\Services\Scraping\Shared;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RoasterImporter
{
    public function __construct(private ?ScraperRegistry $registry = null)
    {
        $this->registry = $registry ?? new ScraperRegistry();
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

        $roaster = Roaster::updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'city' => $city ?? 'Unknown',
                'region' => $region,
                'website' => $website,
                'has_shipping' => true,
                'is_active' => true,
            ]
        );

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
        $description = $this->cleanDescription((string) ($c['description'] ?? ''));
        $payload = [
            'source_id' => $c['source_id'] ?? null,
            'name' => $c['name'],
            'origin' => $this->inferOrigin($c['name']),
            'description' => $description,
            'tasting_notes' => $this->extractTastingNotes($description),
            'product_url' => $c['product_url'] ?? null,
            'image_url' => $c['image_url'] ?? null,
            'is_blend' => $c['is_blend'] ?? false,
            'removed_at' => null, // un-remove if it had been soft-removed
        ];

        if ($existing) {
            $existing->fill($payload)->save();
            $coffee = $existing;
            $coffee->variants()->delete();
        } else {
            $coffee = $roaster->coffees()->create($payload);
        }

        foreach ($c['variants'] ?? [] as $v) {
            $coffee->variants()->create([
                'bag_weight_grams' => $v['grams'],
                'price' => $v['price'],
                'in_stock' => $v['available'] ?? true,
                'purchase_link' => $c['product_url'] ?? $roaster->website,
            ]);
        }
        return $coffee;
    }

    private function inferNameFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $host = preg_replace('/^(www|shop)\./', '', $host);
        $base = explode('.', $host)[0];
        return ucwords(str_replace(['-', '_'], ' ', $base));
    }

    private function cleanDescription(string $raw): ?string
    {
        $s = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace("/\n{3,}/", "\n\n", $s);
        $s = preg_replace('/[ \t]+/', ' ', $s);
        $s = trim($s);
        return $s !== '' ? $s : null;
    }

    private function extractTastingNotes(?string $description): ?string
    {
        if (!$description) return null;
        if (preg_match('/(?:tasting\s+notes?|flavou?r\s+notes?|notes?)\s*[:\-—]\s*([^\n.]{3,120})/i', $description, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function inferOrigin(string $title): string
    {
        $countries = ['Ethiopia', 'Kenya', 'Colombia', 'Brazil', 'Guatemala', 'Costa Rica',
            'Honduras', 'Mexico', 'Peru', 'Rwanda', 'Burundi', 'Indonesia', 'Sumatra',
            'Yemen', 'Panama', 'El Salvador', 'Nicaragua', 'Tanzania', 'Uganda',
            'Bolivia', 'Ecuador', 'Jamaica', 'India'];
        foreach ($countries as $c) {
            if (stripos($title, $c) !== false) return $c;
        }
        return '';
    }
}
