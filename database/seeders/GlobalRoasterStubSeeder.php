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
            // North America
            'Portland' => [45.5152, -122.6784],
            'Oakland' => [37.8044, -122.2712],
            'Bentonville' => [36.3729, -94.2088],
            'Durham' => [35.9940, -78.8986],
            'Santa Cruz' => [36.9741, -122.0308],
            'Brooklyn' => [40.6782, -73.9442],
            'Boston' => [42.3601, -71.0589],
            'Grand Rapids' => [42.9634, -85.6681],
            'Asheville' => [35.5951, -82.5515],
            'Toronto' => [43.6532, -79.3832],
            'Calgary' => [51.0447, -114.0719],
            // UK
            'London' => [51.5074, -0.1278],
            'Cornwall' => [50.2660, -5.0527],
            // Nordics
            'Oslo' => [59.9139, 10.7522],
            'Copenhagen' => [55.6761, 12.5683],
            'Aarhus' => [56.1629, 10.2039],
            'Stockholm' => [59.3293, 18.0686],
            'Helsingborg' => [56.0465, 12.6945],
            'Gothenburg' => [57.7089, 11.9746],
            // Europe
            'Berlin' => [52.5200, 13.4050],
            'Amsterdam' => [52.3676, 4.9041],
            // APAC
            'Canberra' => [-35.2809, 149.1300],
            'Melbourne' => [-37.8136, 144.9631],
            'Sydney' => [-33.8688, 151.2093],
            'Tokyo' => [35.6762, 139.6503],
            'Osaka' => [34.6937, 135.5023],
        ];

        // Each row: [name, city, state/province (or null), country_code, website]
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

            // ── Canada (other provinces) ─────────────────────────────────
            ['Subtext Coffee Roasters', 'Toronto', 'Ontario', 'CA', 'https://www.subtextcoffee.com'],
            ['Pilot Coffee Roasters', 'Toronto', 'Ontario', 'CA', 'https://pilotcoffeeroasters.com'],
            ['Phil & Sebastian', 'Calgary', 'Alberta', 'CA', 'https://philsebastian.com'],

            // ── United Kingdom (countries within, not states) ────────────
            ['Square Mile Coffee Roasters', 'London', 'England', 'GB', 'https://shop.squaremilecoffee.com'],
            ['Workshop Coffee', 'London', 'England', 'GB', 'https://www.workshopcoffee.com'],
            ['Origin Coffee', 'Cornwall', 'England', 'GB', 'https://www.origincoffee.co.uk'],
            ['Assembly Coffee', 'London', 'England', 'GB', 'https://assemblycoffee.co.uk'],

            // ── Norway / Denmark / Sweden — no sub-national label ───────
            ['Tim Wendelboe', 'Oslo', null, 'NO', 'https://www.timwendelboe.no'],
            ['The Coffee Collective', 'Copenhagen', null, 'DK', 'https://coffeecollective.dk'],
            ['La Cabra Coffee Roasters', 'Aarhus', null, 'DK', 'https://lacabra.dk'],
            ['Prolog Coffee Bar', 'Copenhagen', null, 'DK', 'https://prologcoffee.com'],
            ['Drop Coffee', 'Stockholm', null, 'SE', 'https://www.dropcoffee.com'],
            ['Koppi', 'Helsingborg', null, 'SE', 'https://koppi.se'],
            ['Per Nordby', 'Gothenburg', null, 'SE', 'https://pernordby.com'],

            // ── Germany ──────────────────────────────────────────────────
            ['The Barn', 'Berlin', 'Berlin', 'DE', 'https://www.thebarn.de'],
            ['Bonanza Coffee Roasters', 'Berlin', 'Berlin', 'DE', 'https://bonanzacoffee.de'],
            ['19grams', 'Berlin', 'Berlin', 'DE', 'https://19grams.coffee'],

            // ── Netherlands ──────────────────────────────────────────────
            ['Friedhats', 'Amsterdam', 'North Holland', 'NL', 'https://friedhats.com'],
            ['White Label Coffee', 'Amsterdam', 'North Holland', 'NL', 'https://www.whitelabelcoffee.nl'],
            ['Lot Sixty One Coffee Roasters', 'Amsterdam', 'North Holland', 'NL', 'https://lotsixtyonecoffee.com'],

            // ── Australia ────────────────────────────────────────────────
            ['ONA Coffee', 'Canberra', 'Australian Capital Territory', 'AU', 'https://onacoffee.com.au'],
            ['Market Lane Coffee', 'Melbourne', 'Victoria', 'AU', 'https://marketlane.com.au'],
            ['Single Origin Roasters', 'Sydney', 'New South Wales', 'AU', 'https://www.singleoriginroasters.com.au'],
            ['Industry Beans', 'Melbourne', 'Victoria', 'AU', 'https://industrybeans.com'],

            // ── Japan ────────────────────────────────────────────────────
            ['Glitch Coffee & Roasters', 'Tokyo', 'Tokyo', 'JP', 'https://shop.glitchcoffee.com'],
            ['Onibus Coffee', 'Tokyo', 'Tokyo', 'JP', 'https://onibuscoffee.com'],
            ['Mel Coffee Roasters', 'Osaka', 'Osaka', 'JP', 'https://melcoffeeroasters.com'],
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
