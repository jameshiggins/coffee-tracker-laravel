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
            $coffee = $roaster->coffees()->create([
                'name' => $c['name'],
                'origin' => $this->inferOrigin($c['name']),
                'tasting_notes' => Str::limit(trim($c['description']), 500, '') ?: null,
                'is_blend' => $c['is_blend'] ?? false,
            ]);
            foreach ($c['variants'] as $v) {
                $coffee->variants()->create([
                    'bag_weight_grams' => $v['grams'],
                    'price' => $v['price'],
                    'in_stock' => $v['available'],
                    'is_default' => $v['is_default'],
                    'purchase_link' => $this->normalizeWebsite($url),
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
