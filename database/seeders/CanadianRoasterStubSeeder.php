<?php

namespace Database\Seeders;

use App\Models\Roaster;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Stub entries for well-known Canadian specialty roasters outside BC.
 * Stubs deliberately include NO coffee data — the user populates inventory
 * by running each roaster's URL through /admin/import. Stubs only carry
 * verifiable static facts: name, city, province, website. Everything else
 * (current beans, prices, exact address, ships-to) gets imported.
 */
class CanadianRoasterStubSeeder extends Seeder
{
    public function run(): void
    {
        $cityCoords = [
            'Toronto' => [43.6532, -79.3832],
            'Calgary' => [51.0447, -114.0719],
            'Montreal' => [45.5017, -73.5673],
            'Edmonton' => [53.5461, -113.4938],
            'Halifax' => [44.6488, -63.5752],
            'Ottawa' => [45.4215, -75.6972],
            'Winnipeg' => [49.8951, -97.1384],
        ];

        // Canadian specialty roasters outside BC — name, city, province, website.
        $stubs = [
            ['Subtext Coffee Roasters', 'Toronto', 'Ontario', 'https://www.subtextcoffee.com'],
            ['Pilot Coffee Roasters', 'Toronto', 'Ontario', 'https://pilotcoffeeroasters.com'],
            ['Detour Coffee Roasters', 'Toronto', 'Ontario', 'https://detourcoffee.com'],
            ['Phil & Sebastian', 'Calgary', 'Alberta', 'https://philsebastian.com'],
            ['Monogram Coffee', 'Calgary', 'Alberta', 'https://monogramcoffee.com'],
            ['Rosso Coffee Roasters', 'Calgary', 'Alberta', 'https://rossocoffeeroasters.com'],
        ];

        foreach ($stubs as [$name, $city, $region, $website]) {
            $coords = $cityCoords[$city] ?? [null, null];
            Roaster::updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'city' => $city,
                    'region' => $region,
                    'country_code' => 'CA',
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
