<?php

namespace App\Services;

use App\Models\Coffee;
use App\Models\Roaster;
use Illuminate\Support\Str;

class RoasterImporter
{
    /**
     * Import (or refresh) a roaster from a Shopify storefront URL.
     * Replaces existing coffees + variants for a clean snapshot of current inventory.
     */
    public function import(string $url, ?string $name = null, ?string $city = null, ?string $region = null): Roaster
    {
        $coffees = ShopifyScraper::fetch($url);

        $name ??= $this->inferNameFromUrl($url);
        $slug = Str::slug($name);

        $roaster = Roaster::updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'city' => $city ?? 'Unknown',
                'region' => $region,
                'website' => $this->normalizeWebsite($url),
                'has_shipping' => true, // any roaster with a Shopify storefront sells online
                'is_active' => true,
            ]
        );

        // Wipe existing coffee data so we have an authoritative snapshot.
        $roaster->coffees()->delete();

        foreach ($coffees as $c) {
            $description = $this->cleanDescription($c['description'] ?? '');
            $productUrl = !empty($c['handle'])
                ? rtrim($this->normalizeWebsite($url), '/') . '/products/' . $c['handle']
                : null;
            $coffee = $roaster->coffees()->create([
                'name' => $c['name'],
                'origin' => $this->inferOrigin($c['name']),
                'description' => $description,
                'tasting_notes' => $this->extractTastingNotes($description),
                'product_url' => $productUrl,
                'is_blend' => $c['is_blend'] ?? false,
            ]);
            foreach ($c['variants'] as $v) {
                $coffee->variants()->create([
                    'bag_weight_grams' => $v['grams'],
                    'price' => $v['price'],
                    'in_stock' => $v['available'],
                    'is_default' => $v['is_default'],
                    'purchase_link' => $productUrl ?? $this->normalizeWebsite($url),
                ]);
            }
        }

        return $roaster->fresh('coffees.variants');
    }

    private function inferNameFromUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        $host = preg_replace('/^(www|shop)\./', '', $host);
        $base = explode('.', $host)[0];
        return ucwords(str_replace(['-', '_'], ' ', $base));
    }

    private function normalizeWebsite(string $url): string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        return "{$scheme}://{$host}";
    }

    /**
     * Tidy a Shopify body_html'd-then-stripped description: collapse whitespace,
     * normalise newlines, drop the noise that creeps in from rich-text editors.
     */
    private function cleanDescription(string $raw): ?string
    {
        $s = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse 3+ newlines to 2; collapse runs of horizontal whitespace.
        $s = preg_replace("/\n{3,}/", "\n\n", $s);
        $s = preg_replace('/[ \t]+/', ' ', $s);
        $s = trim($s);
        return $s !== '' ? $s : null;
    }

    /**
     * Heuristic: pull the first short comma-separated flavour list from the
     * description (e.g. "Notes: blueberry, hibiscus, dark chocolate"). Returns
     * null if nothing matches — better empty than wrong.
     */
    private function extractTastingNotes(?string $description): ?string
    {
        if (!$description) return null;
        if (preg_match('/(?:tasting\s+notes?|flavou?r\s+notes?|notes?)\s*[:\-—]\s*([^\n.]{3,120})/i', $description, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /** Best-effort country guess from product title — falls back to empty. */
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
