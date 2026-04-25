<?php

namespace Database\Seeders;

use App\Models\Roaster;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Stub entries for well-known specialty roasters worldwide. These deliberately
 * include NO coffee data — the user populates that by running each roaster's URL
 * through /admin/import. Stubs only carry verifiable static facts: name, city,
 * country, website. Anything else (current inventory, prices, exact address, ships-to)
 * gets imported, never fabricated.
 */
class GlobalRoasterStubSeeder extends Seeder
{
    public function run(): void
    {
        $cityCoords = [
            'Portland' => [45.5152, -122.6784],
            'Oakland' => [37.8044, -122.2712],
            'Bentonville' => [36.3729, -94.2088],
            'Durham' => [35.9940, -78.8986],
            'Santa Cruz' => [36.9741, -122.0308],
            'Brooklyn' => [40.6782, -73.9442],
            'Boston' => [42.3601, -71.0589],
            'Grand Rapids' => [42.9634, -85.6681],
            'Asheville' => [35.5951, -82.5515],
            'Seattle' => [47.6062, -122.3321],
            'Chicago' => [41.8781, -87.6298],
            'Austin' => [30.2672, -97.7431],
            'Toronto' => [43.6532, -79.3832],
            'Calgary' => [51.0447, -114.0719],
            'Montreal' => [45.5017, -73.5673],
        ];

        // North American specialty roasters only — name, city, state/province, country, website.
        $stubs = [
            // ── United States ─────────────────────────────────────────────
            ['Stumptown Coffee Roasters', 'Portland', 'Oregon', 'US', 'https://www.stumptowncoffee.com'],
            ['Blue Bottle Coffee', 'Oakland', 'California', 'US', 'https://bluebottlecoffee.com'],
            ['Onyx Coffee Lab', 'Bentonville', 'Arkansas', 'US', 'https://onyxcoffeelab.com'],
            ['Counter Culture Coffee', 'Durham', 'North Carolina', 'US', 'https://counterculturecoffee.com'],
            ['Verve Coffee Roasters', 'Santa Cruz', 'California', 'US', 'https://www.vervecoffee.com'],
            ['Sey Coffee', 'Brooklyn', 'New York', 'US', 'https://www.seycoffee.com'],
            ['George Howell Coffee', 'Boston', 'Massachusetts', 'US', 'https://www.georgehowellcoffee.com'],
            ['Madcap Coffee', 'Grand Rapids', 'Michigan', 'US', 'https://madcapcoffee.com'],
            ['Methodical Coffee', 'Asheville', 'North Carolina', 'US', 'https://methodicalcoffee.com'],
            ['Heart Coffee Roasters', 'Portland', 'Oregon', 'US', 'https://www.heartroasters.com'],
            ['Coava Coffee Roasters', 'Portland', 'Oregon', 'US', 'https://coavacoffee.com'],
            ['Victrola Coffee Roasters', 'Seattle', 'Washington', 'US', 'https://www.victrolacoffee.com'],
            ['Intelligentsia Coffee', 'Chicago', 'Illinois', 'US', 'https://www.intelligentsia.com'],
            ['Cuvee Coffee', 'Austin', 'Texas', 'US', 'https://cuveecoffee.com'],

            // ── Canada (other provinces) ─────────────────────────────────
            ['Subtext Coffee Roasters', 'Toronto', 'Ontario', 'CA', 'https://www.subtextcoffee.com'],
            ['Pilot Coffee Roasters', 'Toronto', 'Ontario', 'CA', 'https://pilotcoffeeroasters.com'],
            ['Phil & Sebastian', 'Calgary', 'Alberta', 'CA', 'https://philsebastian.com'],
            ['Detour Coffee Roasters', 'Toronto', 'Ontario', 'CA', 'https://detourcoffee.com'],
            ['Monogram Coffee', 'Calgary', 'Alberta', 'CA', 'https://monogramcoffee.com'],
            ['Rosso Coffee Roasters', 'Calgary', 'Alberta', 'CA', 'https://rossocoffeeroasters.com'],
        ];

        foreach ($stubs as [$name, $city, $region, $country, $website]) {
            $coords = $cityCoords[$city] ?? [null, null];
            Roaster::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'city' => $city,
                    'region' => $region,
                    'country_code' => $country,
                    'website' => $website,
                    'has_shipping' => true,
                    'is_active' => true,
                    'latitude' => $coords[0],
                    'longitude' => $coords[1],
                ]
            );
        }
    }
}
