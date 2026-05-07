<?php

namespace Database\Seeders;

use App\Models\Roaster;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RoasterSeeder extends Seeder
{
    public function run(): void
    {
        $roasters = $this->roasterData();
        $cityCoords = $this->cityCoords();

        foreach ($roasters as $data) {
            $coffeeData = $data['coffees'] ?? [];
            unset($data['coffees']);

            $data['slug'] = Str::slug($data['name']);
            $data['has_shipping']     = $data['has_shipping']     ?? false;
            $data['has_subscription'] = $data['has_subscription'] ?? false;
            $data['is_active']        = $data['is_active']        ?? true;
            $data['country_code']     = $data['country_code']     ?? 'CA';
            $data['ships_to']         = $data['ships_to']         ?? ($data['has_shipping'] ? ['CA'] : null);

            if (empty($data['latitude']) && !empty($data['city']) && isset($cityCoords[$data['city']])) {
                [$data['latitude'], $data['longitude']] = $cityCoords[$data['city']];
            }

            // Idempotent — running this seeder twice (e.g. after a top-up
            // migration) must not crash on the unique slug constraint.
            $roaster = Roaster::updateOrCreate(['slug' => $data['slug']], $data);

            foreach ($coffeeData as $coffeeRow) {
                $variants = $coffeeRow['variants'];
                unset($coffeeRow['variants']);

                $coffee = $roaster->coffees()
                    ->where('name', $coffeeRow['name'])
                    ->first()
                    ?: $roaster->coffees()->create($coffeeRow);

                foreach ($variants as $v) {
                    $coffee->variants()->updateOrCreate(
                        ['bag_weight_grams' => $v['grams']],
                        [
                            'price'         => $v['price'],
                            'in_stock'      => $v['in_stock'] ?? true,
                            'purchase_link' => $v['purchase_link'] ?? $roaster->website ?? null,
                        ]
                    );
                }
            }
        }
    }

    private function cityCoords(): array
    {
        return [
            'Vancouver' => [49.2827, -123.1207],
            'Victoria' => [48.4284, -123.3656],
            'Kelowna' => [49.8880, -119.4960],
            'Nelson' => [49.4928, -117.2948],
            'Penticton' => [49.4991, -119.5937],
            'Prince George' => [53.9171, -122.7497],
            'Rossland' => [49.0792, -117.7990],
            'Salt Spring Island' => [48.8000, -123.5000],
        ];
    }

    private function roasterData(): array
    {
        return [
            // ── Victoria & Vancouver Island ─────────────────────────────
            ['name' => 'Fernwood Coffee Company', 'region' => 'British Columbia', 'city' => 'Victoria',
                'website' => 'https://fernwoodcoffee.com', 'has_shipping' => true,
                'coffees' => [
                    ['name' => 'Ethiopian Yirgacheffe', 'origin' => 'Ethiopia, Yirgacheffe',
                        'variants' => [
                            ['grams' => 340, 'price' => 22.50],
                            ['grams' => 1000, 'price' => 60.00],
                        ]],
                ]],

            ['name' => 'Bows Coffee Roasters', 'region' => 'British Columbia', 'city' => 'Victoria',
                'website' => 'https://bowscoffee.com', 'has_shipping' => true, 'has_subscription' => true,
                'subscription_notes' => 'Bi-weekly or monthly, pick your roast preference',
                'coffees' => [
                    ['name' => 'Colombian Huila', 'origin' => 'Colombia, Huila',
                        'process' => 'washed', 'roast_level' => 'light',
                        'variants' => [
                            ['grams' => 250, 'price' => 21.00],
                            ['grams' => 340, 'price' => 26.00],
                        ]],
                ]],

            ['name' => 'Drumroaster', 'region' => 'British Columbia', 'city' => 'Victoria',
                'website' => 'https://drumroaster.com', 'has_shipping' => true,
                'coffees' => [
                    ['name' => 'Guatemala Antigua', 'origin' => 'Guatemala, Antigua', 'process' => 'washed',
                        'variants' => [
                            ['grams' => 340, 'price' => 24.00],
                            ['grams' => 454, 'price' => 31.00],
                        ]],
                ]],

            ['name' => 'Discovery Coffee', 'region' => 'British Columbia', 'city' => 'Victoria',
                'website' => 'https://discoverycoffee.com', 'has_shipping' => true, 'has_subscription' => true,
                'subscription_notes' => 'Choose frequency, size, and your preferred roast profile',
                'coffees' => [
                    ['name' => 'Ethiopia Worka Chelbesa', 'origin' => 'Ethiopia, Gedeo',
                        'process' => 'washed', 'roast_level' => 'light', 'varietal' => 'Heirloom',
                        'tasting_notes' => 'Peach, nectarine, floral',
                        'variants' => [
                            ['grams' => 250, 'price' => 24.00],
                            ['grams' => 340, 'price' => 30.50],
                        ]],
                    ['name' => 'Mexico Oaxaca', 'origin' => 'Mexico, Oaxaca',
                        'process' => 'natural', 'roast_level' => 'medium',
                        'variants' => [
                            ['grams' => 250, 'price' => 19.00],
                            ['grams' => 340, 'price' => 24.50],
                        ]],
                ]],

            // ── Interior & Kootenays ────────────────────────────────────
            ['name' => 'Oso Negro Coffee', 'region' => 'British Columbia', 'city' => 'Nelson',
                'website' => 'https://osonegrocoffee.com', 'has_shipping' => true,
                'coffees' => [
                    ['name' => 'Costa Rican Tarrazú', 'origin' => 'Costa Rica, Tarrazú', 'process' => 'washed',
                        'variants' => [
                            ['grams' => 340, 'price' => 26.50],
                            ['grams' => 454, 'price' => 33.00],
                            ['grams' => 1000, 'price' => 68.00],
                        ]],
                ]],

            ['name' => 'Seven Summits Coffee', 'region' => 'British Columbia', 'city' => 'Rossland',
                'website' => 'https://sevensummitscoffee.com', 'has_shipping' => true,
                'coffees' => [
                    ['name' => 'Kenyan AA', 'origin' => 'Kenya', 'process' => 'washed',
                        'variants' => [
                            ['grams' => 340, 'price' => 25.00],
                            ['grams' => 1000, 'price' => 65.00],
                        ]],
                ]],

            ['name' => 'Side Door Coffee', 'region' => 'British Columbia', 'city' => 'Prince George',
                'has_shipping' => false,
                'coffees' => [
                    ['name' => 'Ethiopia Yirgacheffe', 'origin' => 'Ethiopia, Yirgacheffe',
                        'process' => 'washed', 'roast_level' => 'light',
                        'variants' => [
                            ['grams' => 250, 'price' => 21.00],
                        ]],
                    ['name' => 'House Dark', 'origin' => 'Brazil / Colombia', 'roast_level' => 'dark',
                        'variants' => [
                            ['grams' => 340, 'price' => 18.00],
                            ['grams' => 1000, 'price' => 48.00],
                        ]],
                ]],

            // ── Okanagan ────────────────────────────────────────────────
            ['name' => 'Anarchy Coffee Roasters', 'region' => 'British Columbia', 'city' => 'Penticton',
                'website' => 'https://anarchycoffee.ca', 'has_shipping' => true,
                'coffees' => [
                    ['name' => 'Brazilian Santos', 'origin' => 'Brazil, Santos', 'process' => 'natural',
                        'variants' => [
                            ['grams' => 340, 'price' => 21.00],
                            ['grams' => 454, 'price' => 27.00],
                        ]],
                ]],

            ['name' => 'Moja Coffee', 'region' => 'British Columbia', 'city' => 'Kelowna',
                'website' => 'https://mojacoffee.com', 'has_shipping' => true,
                'coffees' => [
                    ['name' => 'Ethiopian Single Origin', 'origin' => 'Ethiopia, Guji Shakiso',
                        'process' => 'natural', 'roast_level' => 'light', 'varietal' => 'Heirloom',
                        'tasting_notes' => 'Strawberry jam, lemon verbena, wine',
                        'variants' => [
                            ['grams' => 340, 'price' => 16.50],
                            ['grams' => 1000, 'price' => 45.00],
                        ]],
                    ['name' => 'Rwanda Huye Mountain', 'origin' => 'Rwanda, Huye District',
                        'process' => 'washed', 'roast_level' => 'light',
                        'variants' => [
                            ['grams' => 250, 'price' => 22.00],
                        ]],
                ]],

            // ── Vancouver & Lower Mainland ──────────────────────────────
            ['name' => 'Agro Roasters', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://agroroasters.com', 'has_shipping' => true, 'has_subscription' => true,
                'subscription_notes' => 'Monthly rotation of seasonal single-origins',
                'coffees' => [
                    ['name' => 'Single Origin Blend', 'origin' => 'Multi-origin',
                        'variants' => [
                            ['grams' => 340, 'price' => 17.00],
                            ['grams' => 1000, 'price' => 44.00],
                        ]],
                    ['name' => 'Ethiopia Guji', 'origin' => 'Ethiopia, Guji',
                        'process' => 'natural', 'roast_level' => 'light', 'varietal' => 'Heirloom',
                        'tasting_notes' => 'Blueberry, hibiscus, dark chocolate',
                        'variants' => [
                            ['grams' => 250, 'price' => 24.00],
                        ]],
                ]],

            ['name' => 'Timbertrain', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://timbertrain.ca', 'has_shipping' => true, 'has_subscription' => true,
                'subscription_notes' => 'Monthly subscription with curated seasonal selections',
                'coffees' => [
                    ['name' => 'Colombian Supremo', 'origin' => 'Colombia',
                        'process' => 'washed', 'roast_level' => 'medium',
                        'variants' => [
                            ['grams' => 340, 'price' => 21.50],
                            ['grams' => 1000, 'price' => 56.00],
                        ]],
                ]],

            ['name' => 'Matchstick', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://matchstickyvr.com', 'has_shipping' => true,
                'coffees' => [
                    ['name' => 'Guatemala Huehuetenango', 'origin' => 'Guatemala, Huehuetenango',
                        'process' => 'washed', 'roast_level' => 'light',
                        'variants' => [
                            ['grams' => 340, 'price' => 21.00],
                        ]],
                ]],

            ['name' => 'Pallet', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://palletcoffeeroasters.com', 'has_shipping' => true, 'has_subscription' => true,
                'coffees' => [
                    ['name' => 'Premium Single Origin', 'origin' => 'Colombia',
                        'process' => 'washed', 'roast_level' => 'light',
                        'variants' => [
                            ['grams' => 340, 'price' => 27.50],
                            ['grams' => 1000, 'price' => 72.00],
                        ]],
                ]],

            ['name' => 'Nemesis', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://nemesiscoffee.ca', 'has_shipping' => true,
                'coffees' => [
                    ['name' => 'Specialty Single Origin', 'origin' => 'Ethiopia',
                        'process' => 'natural', 'roast_level' => 'light',
                        'variants' => [
                            ['grams' => 340, 'price' => 27.50],
                        ]],
                ]],

            ['name' => 'Alluvium Coffee', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://www.alluviumcoffee.com', 'has_shipping' => true,
                'coffees' => []],

            ['name' => 'Rogue Wave Coffee', 'region' => 'Alberta', 'city' => 'Edmonton',
                'website' => 'https://roguewavecoffee.ca', 'has_shipping' => true,
                'coffees' => []],

            ['name' => 'Modus', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://moduscoffee.com', 'has_shipping' => true,
                'coffees' => [
                    ['name' => 'Artisan Single Origin', 'origin' => 'Honduras',
                        'process' => 'honey', 'roast_level' => 'medium',
                        'variants' => [
                            ['grams' => 340, 'price' => 22.00],
                            ['grams' => 1000, 'price' => 58.00],
                        ]],
                ]],

            ['name' => 'Hey Happy', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://heyhappycoffee.com', 'has_shipping' => true,
                'coffees' => [
                    ['name' => 'Signature Single Origin', 'origin' => 'Ethiopia',
                        'process' => 'washed', 'roast_level' => 'light',
                        'variants' => [
                            ['grams' => 340, 'price' => 24.00],
                        ]],
                ]],

            ['name' => 'JJ Bean', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://jjbean.ca', 'has_shipping' => true, 'has_subscription' => true,
                'subscription_notes' => 'Subscribe and save 10%, choose your cadence',
                'coffees' => [
                    ['name' => 'Organic Single Origin', 'origin' => 'Peru',
                        'process' => 'washed', 'roast_level' => 'medium',
                        'variants' => [
                            ['grams' => 340, 'price' => 24.50],
                            ['grams' => 1000, 'price' => 64.00],
                        ]],
                    ['name' => 'East Van Espresso', 'origin' => 'Brazil / Colombia blend',
                        'process' => 'washed', 'roast_level' => 'medium',
                        'tasting_notes' => 'Chocolate, hazelnut, caramel finish',
                        'variants' => [
                            ['grams' => 340, 'price' => 21.00],
                            ['grams' => 1000, 'price' => 54.00],
                        ]],
                ]],

            ['name' => '49th Parallel', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://49thcoffee.com', 'has_shipping' => true, 'has_subscription' => true,
                'coffees' => [
                    ['name' => 'Single Origin Reserve', 'origin' => 'Guatemala',
                        'process' => 'washed', 'roast_level' => 'light',
                        'variants' => [
                            ['grams' => 340, 'price' => 25.00],
                            ['grams' => 1000, 'price' => 66.00],
                        ]],
                ]],

            ['name' => 'Kea Coffee', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://keacoffee.com', 'has_shipping' => true,
                'coffees' => [
                    ['name' => 'Hawaiian Kona Style', 'origin' => 'Hawaii / Multi-origin',
                        'variants' => [
                            ['grams' => 300, 'price' => 25.00],
                        ]],
                ]],

            ['name' => 'Prototype', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://prototypecoffee.com', 'has_shipping' => true, 'has_subscription' => true,
                'subscription_notes' => 'Monthly single-origin drops, always fresh-roasted',
                'coffees' => [
                    ['name' => 'Experimental Single Origin', 'origin' => 'Colombia, Nariño',
                        'process' => 'washed', 'roast_level' => 'light', 'varietal' => 'Pink Bourbon',
                        'tasting_notes' => 'Raspberry, rose, citrus zest',
                        'variants' => [
                            ['grams' => 250, 'price' => 23.00],
                            ['grams' => 340, 'price' => 30.00],
                        ]],
                ]],

            ['name' => 'Moving Coffee', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://movingcoffee.com', 'has_shipping' => true,
                'coffees' => [
                    ['name' => 'Micro-lot Single Origin', 'origin' => 'Kenya',
                        'process' => 'washed', 'roast_level' => 'light',
                        'variants' => [
                            ['grams' => 340, 'price' => 28.00],
                        ]],
                ]],

            ['name' => 'East Van Roasters', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://eastvanroasters.com', 'has_shipping' => true,
                'coffees' => [
                    ['name' => 'Local Single Origin', 'origin' => 'Colombia',
                        'process' => 'washed', 'roast_level' => 'medium',
                        'variants' => [
                            ['grams' => 340, 'price' => 22.00],
                            ['grams' => 454, 'price' => 28.00],
                        ]],
                ]],

            ['name' => 'Rocanini', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://rocanini.com', 'has_shipping' => true,
                'coffees' => [
                    ['name' => 'Italian Style Single Origin', 'origin' => 'Brazil',
                        'process' => 'natural', 'roast_level' => 'medium-dark',
                        'variants' => [
                            ['grams' => 340, 'price' => 24.00],
                            ['grams' => 1000, 'price' => 62.00],
                        ]],
                ]],

            ['name' => 'Origins Organic', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://originsorganic.com', 'has_shipping' => true,
                'coffees' => [
                    ['name' => 'Certified Organic Single Origin', 'origin' => 'Peru',
                        'process' => 'washed', 'roast_level' => 'medium',
                        'variants' => [
                            ['grams' => 340, 'price' => 26.00],
                            ['grams' => 1000, 'price' => 68.00],
                        ]],
                ]],

            ['name' => 'Salt Spring Coffee', 'region' => 'British Columbia', 'city' => 'Salt Spring Island',
                'website' => 'https://saltspringcoffee.com', 'has_shipping' => true, 'has_subscription' => true,
                'coffees' => [
                    ['name' => 'Island Single Origin', 'origin' => 'Sumatra',
                        'process' => 'wet-hulled', 'roast_level' => 'medium',
                        'variants' => [
                            ['grams' => 340, 'price' => 23.50],
                            ['grams' => 1000, 'price' => 60.00],
                        ]],
                ]],

            ['name' => 'Luna Coffee', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://lunacoffee.ca', 'has_shipping' => true, 'has_subscription' => true,
                'coffees' => [
                    ['name' => 'Subscription Single Origin', 'origin' => 'Guatemala',
                        'process' => 'washed', 'roast_level' => 'light',
                        'variants' => [
                            ['grams' => 250, 'price' => 18.50],
                            ['grams' => 340, 'price' => 24.00],
                        ]],
                ]],

            ['name' => 'Continuum Coffee', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://continuumcoffee.com', 'has_shipping' => true,
                'coffees' => [
                    ['name' => 'Premium Single Origin', 'origin' => 'Ethiopia',
                        'process' => 'natural', 'roast_level' => 'light',
                        'variants' => [
                            ['grams' => 340, 'price' => 26.00],
                        ]],
                ]],

            ['name' => 'West End Coffee Roasters', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://westendcoffee.ca', 'has_shipping' => true,
                'coffees' => [
                    ['name' => 'Jamaican Blue Mountain', 'origin' => 'Jamaica, Blue Mountain',
                        'variants' => [
                            ['grams' => 227, 'price' => 28.00],
                            ['grams' => 340, 'price' => 35.00],
                        ]],
                ]],

            ['name' => 'Foglifter Coffee Roasters', 'region' => 'British Columbia', 'city' => 'Vancouver',
                'website' => 'https://foglifter.ca', 'has_shipping' => true,
                'coffees' => [
                    ['name' => 'Mexican Chiapas', 'origin' => 'Mexico, Chiapas',
                        'process' => 'washed', 'roast_level' => 'medium',
                        'variants' => [
                            ['grams' => 340, 'price' => 23.50],
                            ['grams' => 1000, 'price' => 60.00],
                        ]],
                ]],
        ];
    }
}
