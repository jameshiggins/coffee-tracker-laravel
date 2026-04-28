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
     * Replace this roaster's coffees with the freshly-scraped set.
     *
     * NOTE: this is the simple delete-and-recreate behaviour from before the
     * grilling decisions. The next commit upgrades this to upsert-on-(platform,
     * source_id) + soft-remove (Q1+Q2) so that user tasting links survive.
     */
    private function syncCoffees(Roaster $roaster, array $coffees): void
    {
        $roaster->coffees()->delete();

        foreach ($coffees as $c) {
            $description = $this->cleanDescription((string) ($c['description'] ?? ''));
            $coffee = $roaster->coffees()->create([
                'name' => $c['name'],
                'origin' => $this->inferOrigin($c['name']),
                'description' => $description,
                'tasting_notes' => $this->extractTastingNotes($description),
                'product_url' => $c['product_url'] ?? null,
                'is_blend' => $c['is_blend'] ?? false,
            ]);
            $variants = $c['variants'] ?? [];
            $defaultIndex = $this->pickDefaultVariantIndex($variants);
            foreach ($variants as $i => $v) {
                $coffee->variants()->create([
                    'bag_weight_grams' => $v['grams'],
                    'price' => $v['price'],
                    'in_stock' => $v['available'] ?? true,
                    'is_default' => $i === $defaultIndex,
                    'purchase_link' => $c['product_url'] ?? $roaster->website,
                ]);
            }
        }
    }

    private function pickDefaultVariantIndex(array $variants): int
    {
        foreach ($variants as $i => $v) {
            if (($v['available'] ?? true) === true) return $i;
        }
        return 0;
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
