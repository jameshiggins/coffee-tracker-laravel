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
            'Toronto'        => [43.6532, -79.3832],
            'Hamilton'       => [43.2557, -79.8711],
            'Burlington'     => [43.3255, -79.7990],
            'Mississauga'    => [43.5890, -79.6441],
            'Ottawa'         => [45.4215, -75.6972],
            'Kingston'       => [44.2312, -76.4860],
            'London'         => [42.9849, -81.2453],
            'Waterloo'       => [43.4643, -80.5204],
            'Calgary'        => [51.0447, -114.0719],
            'Edmonton'       => [53.5461, -113.4938],
            'Canmore'        => [51.0884, -115.3479],
            'Montreal'       => [45.5017, -73.5673],
            'Quebec City'    => [46.8139, -71.2080],
            'Sherbrooke'     => [45.4042, -71.8929],
            'Halifax'        => [44.6488, -63.5752],
            'Dartmouth'      => [44.6712, -63.5777],
            'Saint John'     => [45.2733, -66.0633],
            'St. Johns'      => [47.5615, -52.7126],
            'Charlottetown'  => [46.2382, -63.1311],
            'Winnipeg'       => [49.8951, -97.1384],
            'Saskatoon'      => [52.1332, -106.6700],
            'Regina'         => [50.4452, -104.6189],
            'Whitehorse'     => [60.7212, -135.0568],
        ];

        // Well-known Canadian specialty roasters outside BC. Each one sells
        // whole bean coffee online. Stubs deliberately have no coffee data —
        // the daily import job (or the admin paste-URL flow) populates beans.
        // URLs verified to resolve and serve a catalog. If you find one
        // that breaks, update here AND the matching DB row (admin/roasters).
        // Format: [name, city, province, website].
        $stubs = [
            // Ontario
            ['Subtext Coffee Roasters',     'Toronto',     'Ontario', 'https://www.subtextcoffee.com'],
            ['Pilot Coffee Roasters',       'Toronto',     'Ontario', 'https://pilotcoffeeroasters.com'],
            ['Detour Coffee Roasters',      'Hamilton',    'Ontario', 'https://detourcoffee.com'],
            ['De Mello Coffee',             'Toronto',     'Ontario', 'https://www.demellocoffee.com'],
            ['Reunion Coffee Roasters',     'Mississauga', 'Ontario', 'https://reunioncoffeeroasters.com'],
            ['Propeller Coffee Co.',        'Toronto',     'Ontario', 'https://propellercoffee.com'],
            ['Sam James Coffee Bar',        'Toronto',     'Ontario', 'https://samjamescoffeebar.com'],
            ['Hatch Coffee',                'Toronto',     'Ontario', 'https://www.hatch.coffee'],
            ['Quietly Coffee',              'Toronto',     'Ontario', 'https://quietlycoffee.com'],
            ['Boxcar Social',               'Toronto',     'Ontario', 'https://boxcarsocial.ca'],
            ['Rooster Coffee House',        'Toronto',     'Ontario', 'https://www.roostercoffeehouse.com'],
            ['Hidden Grounds Coffee',       'Burlington',  'Ontario', 'https://hiddengroundscoffee.com'],
            ['Bridgehead Coffee',           'Ottawa',      'Ontario', 'https://www.bridgehead.ca'],
            ['Equator Coffee Roasters',     'Ottawa',      'Ontario', 'https://www.equator.ca'],
            ['Happy Goat Coffee',           'Ottawa',      'Ontario', 'https://happygoatcoffee.com'],
            ['Mighty Valley Coffee',        'Kingston',    'Ontario', 'https://mightyvalleycoffee.com'],
            ['Las Chicas del Café',         'London',      'Ontario', 'https://laschicasdelcafe.com'],
            ['Smile Tiger Coffee Roasters', 'Waterloo',    'Ontario', 'https://smiletigercoffee.com'],

            // Quebec
            ['Café Saint-Henri',            'Montreal',    'Quebec',  'https://sainthenri.ca'],
            ['Café Pista',                  'Montreal',    'Quebec',  'https://cafepista.com'],
            ['Café Pikolo Espresso Bar',    'Montreal',    'Quebec',  'https://pikoloespresso.com'],
            ['Dispatch Coffee',             'Montreal',    'Quebec',  'https://dispatchcoffee.ca'],
            ['Café Myriade',                'Montreal',    'Quebec',  'https://cafemyriade.com'],
            ['Cantook Café Brûlerie',       'Montreal',    'Quebec',  'https://www.cantookcafe.com'],
            ['Café Castel',                 'Quebec City', 'Quebec',  'https://cafecastel.com'],

            // Alberta
            ['Phil & Sebastian',            'Calgary',     'Alberta', 'https://philsebastian.com'],
            ['Monogram Coffee',             'Calgary',     'Alberta', 'https://monogramcoffee.com'],
            ['Rosso Coffee Roasters',       'Calgary',     'Alberta', 'https://rossocoffeeroasters.com'],
            ['Transcend Coffee',            'Edmonton',    'Alberta', 'https://transcendcoffee.ca'],
            ['Ace Coffee Roasters',         'Edmonton',    'Alberta', 'https://acecoffeeroasters.com'],
            ['Iconoclast Koffiehuis',       'Edmonton',    'Alberta', 'https://iconoclastcoffee.com'],
            ['Rooftop Coffee Roasters',     'Fernie',      'British Columbia', 'https://www.rooftopcoffeeroasters.com'],

            // Atlantic
            ['Java Blend Coffee Roasters',  'Halifax',     'Nova Scotia',           'https://javablendcoffee.com'],
            ['Anchored Coffee',             'Dartmouth',   'Nova Scotia',           'https://anchoredcoffee.com'],
            ['Java Moose',                  'Saint John',  'New Brunswick',         'https://javamoose.com'],
            ['Down East Coffee Roasters',   'Saint John',  'New Brunswick',         'https://downeastcoffee.com'],
            ['Jumping Bean Coffee',         'St. Johns',   'Newfoundland and Labrador', 'https://jumpingbean.ca'],
            ['Receiver Coffee Co.',         'Charlottetown','Prince Edward Island', 'https://receivercoffee.com'],

            // Prairies + North
            ['Parlour Coffee',              'Winnipeg',    'Manitoba',     'https://www.parlourcoffee.ca'],
            ['Thom Bargen Coffee Roasters', 'Winnipeg',    'Manitoba',     'https://thombargen.com'],
            ['Little Sister Coffee Maker',  'Winnipeg',    'Manitoba',     'https://littlesistercoffee.com'],
            ['Museo Coffee',                'Saskatoon',   'Saskatchewan', 'https://museocoffee.com'],
            ['Midnight Sun Coffee Roasters','Whitehorse',  'Yukon',        'https://midnightsuncoffeeroasters.com'],
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
