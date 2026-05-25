<?php

namespace App\Console\Commands;

use App\Models\Roaster;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * One-off, idempotent data-corrections pass over the roasters table.
 *
 * Three independent batches:
 *   A) Repoint 5 stale/incorrect roaster website URLs.
 *   B) Fix Quietly Coffee's city (Toronto → Stirling; region unchanged).
 *   C) Ensure 16 roasters exist (create the bare roaster row only — a later
 *      `roasters:import-all` populates their coffees; we do NOT scrape here).
 *
 * SAFE TO RE-RUN. Every step is a no-op once applied:
 *   - URL/city fixes only write when the stored value differs.
 *   - Roaster creation matches an existing row by slug OR name (the same
 *     two-key lookup RoasterImporter uses) and skips it; it never inserts
 *     a duplicate and never overwrites a hand-edited existing roaster.
 *
 * Use `--dry-run` to print the plan without touching the database.
 */
class ApplyRoasterCorrections extends Command
{
    protected $signature = 'roasters:apply-corrections
                            {--dry-run : Print what would change without writing to the database}';

    protected $description = 'Apply one-off roaster data corrections (stale URLs, Quietly city, 16 missing roasters). Idempotent.';

    /**
     * A) name/slug → corrected website. Match is case-insensitive on the
     *    roaster name OR its slug (covers both the short label in the
     *    backlog — "Hatch", "Subtext" — and the full stored name).
     *
     * @var array<int, array{match: array<int, string>, website: string}>
     */
    private const URL_FIXES = [
        ['match' => ['Hatch', 'Hatch Coffee'],                'website' => 'https://hatchcrafted.com'],
        ['match' => ['Subtext', 'Subtext Coffee Roasters'],   'website' => 'https://subtext.coffee'],
        ['match' => ['Nemesis', 'Nemesis Coffee'],            'website' => 'https://nemesis.coffee'],
        ['match' => ['Prototype', 'Prototype Coffee'],        'website' => 'https://prototypecoffee.ca'],
        ['match' => ['Luna', 'Luna Coffee'],                  'website' => 'https://enjoylunacoffee.com'],
        // .com was a near-empty stub site → only 1 coffee indexed.
        // .ca is the real shop with the full menu.
        ['match' => ['Continuum', 'Continuum Coffee'],        'website' => 'https://continuumcoffee.ca/'],
        // agroroasters.com 301-redirects to agrocoffee.com — repoint so
        // the cascade doesn't chase the redirect every run.
        ['match' => ['Agro', 'Agro Roasters', 'Agro Coffee'], 'website' => 'https://agrocoffee.com'],
    ];

    /**
     * D) Manual address overrides for roasters where the AddressScraper
     *    cascade extracted CSS / nav junk instead of a clean street, then
     *    fell back to the city centroid. Each fix is the verified shop
     *    address (street + postal) plus Nominatim-resolved coordinates,
     *    stamped source='manual' so the cascade leaves them alone forever.
     *
     * Optional fields:
     *   - city: only present when the seeded city was wrong (e.g. Cantook
     *     filed under Montreal but the cafe is in Québec City). The city
     *     overwrite only fires when provided AND differs from current.
     *
     * @var array<int, array{
     *     match: array<int, string>,
     *     street_address: string,
     *     postal_code: string,
     *     latitude: float,
     *     longitude: float,
     *     city?: string,
     * }>
     */
    private const ADDRESS_FIXES = [
        [
            'match' => ['49th Parallel', '49th Parallel Coffee Roasters'],
            'street_address' => '2902 Main St',
            'postal_code' => 'V5T 3G3',
            'latitude' => 49.2591437,
            'longitude' => -123.1007987,
        ],
        [
            'match' => ['Agro', 'Agro Roasters', 'Agro Coffee'],
            'street_address' => '1359 Powell Street',
            'postal_code' => 'V5L 1G8',
            'latitude' => 49.2835561,
            'longitude' => -123.0722000,
        ],
        [
            'match' => ['Prototype', 'Prototype Coffee'],
            'street_address' => '883 East Hastings Street',
            'postal_code' => 'V6A 1R8',
            'latitude' => 49.2813068,
            'longitude' => -123.0852617,
        ],
        [
            'match' => ['East Van Roasters', 'East Van'],
            'street_address' => '319 Carrall St',
            'postal_code' => 'V6B 2J4',
            'latitude' => 49.2821010,
            'longitude' => -123.1045130,
        ],
        // ── batch 2: Nominatim business-name resolution sweep ──
        // Resolved via OSM name+city search after the first manual pass
        // exposed how many roasters the cascade had pinned to their city
        // centroid. Each one is a verified business hit (Nominatim
        // returned a record with house_number + road + postal).
        [
            'match' => ['Café Pikolo Espresso Bar', 'Cafe Pikolo Espresso Bar'],
            'street_address' => '1635 Rue Clark',
            'postal_code' => 'H2X 2R4',
            'latitude' => 45.5107888,
            'longitude' => -73.5668235,
        ],
        [
            'match' => ['Ethica', 'Ethica Coffee Roasters'],
            'street_address' => '213 Sterling Road',
            'postal_code' => 'M6R 2B2',
            'latitude' => 43.6553122,
            'longitude' => -79.4453495,
        ],
        [
            'match' => ['Even Coffee'],
            'street_address' => '267 Rue Saint-Zotique Ouest',
            'postal_code' => 'H2V 4M1',
            'latitude' => 45.5289656,
            'longitude' => -73.6161397,
        ],
        [
            'match' => ['Happy Goat', 'Happy Goat Coffee'],
            'street_address' => '145 Main Street',
            'postal_code' => 'K1S 5V9',
            'latitude' => 45.4100042,
            'longitude' => -75.6782471,
        ],
        [
            'match' => ['Midnight Sun', 'Midnight Sun Coffee Roasters'],
            'street_address' => '21 Waterfront Place',
            'postal_code' => 'Y1A 1C8',
            'latitude' => 60.7307977,
            'longitude' => -135.0640791,
        ],
        [
            'match' => ['Phil & Sebastian', 'Phil and Sebastian'],
            'street_address' => '2207 4 Street SW',
            'postal_code' => 'T2S 1X1',
            'latitude' => 51.0330036,
            'longitude' => -114.0717506,
        ],
        [
            'match' => ['Receiver Coffee', 'Receiver Coffee Co.'],
            'street_address' => '128 Richmond Street',
            'postal_code' => 'C1A 8G8',
            'latitude' => 46.2338344,
            'longitude' => -63.1266042,
        ],
        [
            'match' => ['Sam James', 'Sam James Coffee Bar'],
            'street_address' => '297 Harbord Street',
            'postal_code' => 'M6G 1G7',
            'latitude' => 43.6602502,
            'longitude' => -79.4154118,
        ],
        // ── batch 3: contact-page WebFetch resolution sweep ──
        // Roasters Nominatim's business-name search missed; each address
        // is from the roaster's own contact/about page, then geocoded.
        // Some have a `city` override because the seeder had the wrong
        // metro (e.g. Cantook stored as Montreal — actually Québec City).
        [
            'match' => ['Ace Coffee Roasters', 'Ace Coffee'],
            'street_address' => '10055 80 Avenue NW',
            'postal_code' => 'T6E 1T4',
            'latitude' => 53.5158742,
            'longitude' => -113.4907192,
        ],
        [
            'match' => ['Café Myriade', 'Cafe Myriade', 'Myriade'],
            'street_address' => '1432 Rue Mackay',
            'postal_code' => 'H3G 2H7',
            'latitude' => 45.4960734,
            'longitude' => -73.5778526,
        ],
        [
            'match' => ['Cantook Café Brûlerie', 'Cantook Cafe Brulerie', 'Cantook'],
            'street_address' => '575 Rue Saint-Jean',
            'postal_code' => 'G1R 1P5',
            'latitude' => 46.8099065,
            'longitude' => -71.2200470,
            'city' => 'Québec',
        ],
        [
            'match' => ['De Mello Coffee', 'De Mello'],
            'street_address' => '2489 Yonge Street',
            'postal_code' => 'M4P 2H6',
            'latitude' => 43.7119144,
            'longitude' => -79.3992588,
        ],
        [
            'match' => ['Drumroaster', 'Drumroaster Coffee'],
            'street_address' => '1400 Cowichan Bay Road',
            'postal_code' => 'V8H 0A6',
            'latitude' => 48.7096561,
            'longitude' => -123.6084745,
            'city' => 'Cobble Hill',
        ],
        [
            'match' => ['Equator', 'Equator Coffee Roasters'],
            'street_address' => '451 Ottawa Street',
            'postal_code' => 'K0A 1A0',
            'latitude' => 45.2349666,
            'longitude' => -76.1812299,
            'city' => 'Almonte',
        ],
        [
            'match' => ['House of Funk', 'Funk Coffee'],
            'street_address' => '1025 Dunsmuir St',
            'postal_code' => 'V7X 1M5',
            'latitude' => 49.2863433,
            'longitude' => -123.1205250,
        ],
        [
            'match' => ['Oso Negro Coffee', 'Oso Negro'],
            'street_address' => '604 Ward Street',
            'postal_code' => 'V1L 7B1',
            'latitude' => 49.4908980,
            'longitude' => -117.2932860,
        ],
        [
            'match' => ['Reunion Coffee Roasters', 'Reunion'],
            'street_address' => '2421 Royal Windsor Drive',
            'postal_code' => 'L6J 7X6',
            'latitude' => 43.4934513,
            'longitude' => -79.6491799,
            'city' => 'Oakville',
        ],
        [
            'match' => ['Salt Spring Coffee', 'Salt Spring'],
            'street_address' => '3551 Viking Way',
            'postal_code' => 'V6V 1W1',
            'latitude' => 49.1892856,
            'longitude' => -123.0742950,
            'city' => 'Richmond',
        ],
    ];

    /**
     * C) Roasters that must exist. region = full province name to match the
     *    existing data convention ("Ontario", not "ON"); country_code is
     *    always CA here. has_shipping defaults true (all of these ship);
     *    is_active true.
     *
     * @var array<int, array{name: string, city: string, region: string, website: string}>
     */
    private const REQUIRED_ROASTERS = [
        ['name' => 'Sipstruck Specialty Coffee', 'city' => 'Niagara Falls', 'region' => 'Ontario',          'website' => 'https://sipstruck.com'],
        ['name' => 'Ethica Coffee Roasters',     'city' => 'Toronto',       'region' => 'Ontario',          'website' => 'https://ethicaroasters.com'],
        ['name' => 'Café Yamabiko',              'city' => 'Sutton',        'region' => 'Quebec',           'website' => 'https://yamabikocoffeeroasters.com'],
        ['name' => 'Rabbit Hole Roasters',       'city' => 'Delson',        'region' => 'Quebec',           'website' => 'https://www.rabbitholeroasters.com'],
        ['name' => '94 Celcius',                 'city' => 'Montréal',      'region' => 'Quebec',           'website' => 'https://94celcius.com'],
        ['name' => 'September Coffee Co.',       'city' => 'Ottawa',        'region' => 'Ontario',          'website' => 'https://september.coffee'],
        ['name' => 'Even Coffee',                'city' => 'Montréal',      'region' => 'Quebec',           'website' => 'https://evencoffee.ca'],
        ['name' => 'The Angry Roaster Coffee Co.', 'city' => 'Toronto',     'region' => 'Ontario',          'website' => 'https://theangryroaster.com'],
        ['name' => 'Traffic Coffee Co.',         'city' => 'Montréal',      'region' => 'Quebec',           'website' => 'https://www.trafficcoffee.com'],
        ['name' => 'Colorfull Coffee Corp',      'city' => 'Montréal',      'region' => 'Quebec',           'website' => 'https://colorfullcoffee.com'],
        ['name' => 'Botany Rd',                  'city' => 'Vancouver',     'region' => 'British Columbia', 'website' => 'https://botany-rd.com'],
        ['name' => 'Sorellina Coffee',           'city' => 'Edmonton',      'region' => 'Alberta',          'website' => 'https://sorellina.ca'],
        ['name' => 'R Ki Coffee Lab',            'city' => 'Richmond',      'region' => 'British Columbia', 'website' => 'https://www.rkicoffeelab.com'],
        ['name' => 'House of Funk',              'city' => 'Vancouver',     'region' => 'British Columbia', 'website' => 'https://funk.coffee'],
        ['name' => 'Prairie Lily Coffee',        'city' => 'Lloydminster',  'region' => 'Saskatchewan',     'website' => 'https://prairielilycoffee.com'],
        ['name' => 'Hooray Coffee Lab',          'city' => 'Burnaby',       'region' => 'British Columbia', 'website' => 'https://hooraycoffee.ca'],
        // ── BC scour: Vancouver Island + neighbouring islands (Reddit
        // r/BuyCanadian list, May 2020 + comment additions). All are
        // active small-batch roasters; the cascade fills in coffees on
        // the next import-all run. Skipped: Be Still Coffee (defunct),
        // Burde Beans (likely closed per Alberni Valley Tourism).
        ['name' => 'Peaks Coffee Company',          'city' => 'Duncan',          'region' => 'British Columbia', 'website' => 'https://www.peakscoffeeco.com'],
        ['name' => 'Bows Coffee Roasters',          'city' => 'Victoria',        'region' => 'British Columbia', 'website' => 'https://bowscoffee.com'],
        ['name' => 'Caffe Fantastico',              'city' => 'Victoria',        'region' => 'British Columbia', 'website' => 'https://caffefantastico.com'],
        ['name' => '2% Jazz Coffee',                'city' => 'Victoria',        'region' => 'British Columbia', 'website' => 'https://2percentjazz.com'],
        ['name' => 'Eleven Speed Coffee',           'city' => 'Victoria',        'region' => 'British Columbia', 'website' => 'https://www.elevenspeedcoffee.ca'],
        ['name' => 'Mile Zero Coffee Co.',          'city' => 'Victoria',        'region' => 'British Columbia', 'website' => 'https://www.milezerocoffee.com'],
        ['name' => 'Level Ground Coffee Roasters',  'city' => 'Victoria',        'region' => 'British Columbia', 'website' => 'https://levelground.com'],
        ['name' => 'Esquimalt Roasting Company',    'city' => 'Esquimalt',       'region' => 'British Columbia', 'website' => 'https://www.esquimaltroasting.com'],
        ['name' => 'Smoke & Mirrors Coffee Co.',    'city' => 'Victoria',        'region' => 'British Columbia', 'website' => 'https://smokeandmirrors.coffee'],
        ['name' => 'Black Bear Specialty Coffee',   'city' => 'Victoria',        'region' => 'British Columbia', 'website' => 'https://blackbearcoffee.square.site'],
        ['name' => 'Gulf Islands Roasting Co.',     'city' => 'Nanaimo',         'region' => 'British Columbia', 'website' => 'https://gulfislandsroastingco.com'],
        ['name' => 'Coyote Coffee Roastery',        'city' => 'Parksville',      'region' => 'British Columbia', 'website' => 'https://www.coyotescoffee.ca'],
        ['name' => 'Regard Coffee Roasters',        'city' => 'Nanaimo',         'region' => 'British Columbia', 'website' => 'https://www.regardcoffee.com'],
        ['name' => "Creekmore's Coffee",            'city' => 'Coombs',          'region' => 'British Columbia', 'website' => 'https://www.creekmorescoffee.com'],
        ['name' => 'Mount Maxwell Coffee Roasters', 'city' => 'Salt Spring Island', 'region' => 'British Columbia', 'website' => 'https://mtmaxwell.com'],
        ['name' => 'Oughtred Coffee & Tea',         'city' => 'Delta',           'region' => 'British Columbia', 'website' => 'https://www.oughtred.com'],
        ['name' => 'Red Roaster Coffee',            'city' => 'Gabriola Island', 'region' => 'British Columbia', 'website' => 'https://www.redroaster.ca'],
        ['name' => 'French Press Coffee Roasters',  'city' => 'Qualicum Beach',  'region' => 'British Columbia', 'website' => 'https://www.fpcoffeeroasters.com'],
        ['name' => 'Karma Coffee',                  'city' => 'Coombs',          'region' => 'British Columbia', 'website' => 'https://www.karmacoffee.com'],
        ['name' => 'Royston Roasting Co.',          'city' => 'Royston',         'region' => 'British Columbia', 'website' => 'https://www.rrcocoffee.com'],
        ['name' => 'The Stick in the Mud Coffee House', 'city' => 'Sooke',       'region' => 'British Columbia', 'website' => 'https://stickinthemud.ca'],
        ['name' => 'Rhodos Coffee',                 'city' => 'Courtenay',       'region' => 'British Columbia', 'website' => 'https://rhodoscoffee.ca'],
        ['name' => 'Charge Coffee Company',         'city' => 'Nanaimo',         'region' => 'British Columbia', 'website' => 'https://chargecoffeecompany.com'],
        ['name' => 'Vancouver Island Coffee',       'city' => 'Cowichan Valley', 'region' => 'British Columbia', 'website' => 'https://vi.coffee'],
        ['name' => 'Fix Coffee',                    'city' => 'Vancouver',       'region' => 'British Columbia', 'website' => 'https://www.fixcoffee.ca'],
        ['name' => 'Milano Coffee',                 'city' => 'Vancouver',       'region' => 'British Columbia', 'website' => 'https://milanocoffee.ca'],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        if ($dry) {
            $this->warn('DRY RUN — no database writes will be performed.');
        }

        $changed = 0;
        $changed += $this->applyUrlFixes($dry);
        $changed += $this->applyQuietlyCityFix($dry);
        $changed += $this->ensureRequiredRoasters($dry);
        $changed += $this->applyAddressFixes($dry);
        $changed += $this->clearChromeShippingNotes($dry);

        $this->newLine();
        $this->info($dry
            ? "Dry run complete — {$changed} change(s) WOULD be applied."
            : "Done — {$changed} change(s) applied.");

        return self::SUCCESS;
    }

    /** A) Repoint stale website URLs. Returns the number of rows changed. */
    private function applyUrlFixes(bool $dry): int
    {
        $this->line('A) Website URL corrections');
        $n = 0;

        foreach (self::URL_FIXES as $fix) {
            $roaster = $this->findByNameOrSlug($fix['match']);
            if (!$roaster) {
                $this->line(sprintf('   - skip (not found): %s', implode(' / ', $fix['match'])));
                continue;
            }
            if ($roaster->website === $fix['website']) {
                $this->line(sprintf('   = ok (already correct): %s → %s', $roaster->name, $fix['website']));
                continue;
            }

            $this->line(sprintf(
                '   %s %s: %s → %s',
                $dry ? '~' : '✓',
                $roaster->name,
                $roaster->website ?? '(null)',
                $fix['website']
            ));

            if (!$dry) {
                $roaster->website = $fix['website'];
                $roaster->save();
            }
            $n++;
        }

        return $n;
    }

    /** B) Quietly Coffee: city Toronto → Stirling (region untouched). */
    private function applyQuietlyCityFix(bool $dry): int
    {
        $this->line('B) Quietly Coffee city fix');
        $roaster = $this->findByNameOrSlug(['Quietly', 'Quietly Coffee']);

        if (!$roaster) {
            $this->line('   - skip (Quietly Coffee not found)');
            return 0;
        }
        if ($roaster->city === 'Stirling') {
            $this->line('   = ok (already Stirling)');
            return 0;
        }

        $this->line(sprintf(
            '   %s %s: city %s → Stirling (region stays %s)',
            $dry ? '~' : '✓',
            $roaster->name,
            $roaster->city,
            $roaster->region ?? '(null)'
        ));

        if (!$dry) {
            $roaster->city = 'Stirling';
            $roaster->save();
        }

        return 1;
    }

    /** C) Create any of the 16 roasters that don't already exist. */
    private function ensureRequiredRoasters(bool $dry): int
    {
        $this->line('C) Ensure 16 roasters exist');
        $created = 0;

        foreach (self::REQUIRED_ROASTERS as $spec) {
            $slug = Str::slug($spec['name']);
            $existing = Roaster::whereRaw('LOWER(name) = ?', [Str::lower($spec['name'])])
                ->orWhere('slug', $slug)
                ->first();

            if ($existing) {
                $this->line(sprintf('   = exists: %s (id %d)', $existing->name, $existing->id));
                continue;
            }

            $this->line(sprintf(
                '   %s create: %s — %s, %s — %s',
                $dry ? '~' : '✓',
                $spec['name'],
                $spec['city'],
                $spec['region'],
                $spec['website']
            ));

            if (!$dry) {
                Roaster::create([
                    'name' => $spec['name'],
                    'slug' => $slug,
                    'city' => $spec['city'],
                    'region' => $spec['region'],
                    'country_code' => 'CA',
                    'website' => $spec['website'],
                    'has_shipping' => true,
                    'is_active' => true,
                ]);
            }
            $created++;
        }

        return $created;
    }

    /**
     * D) Manual address overrides. Returns the number of rows changed.
     *
     * Writes street_address / postal_code / lat / lng atomically and
     * stamps source='manual' so the cascade skips them on the next run.
     * If the row was previously marked is_online_only=true, that flag is
     * cleared so the map starts pinning it again.
     */
    private function applyAddressFixes(bool $dry): int
    {
        $this->line('D) Manual address overrides');
        $n = 0;

        foreach (self::ADDRESS_FIXES as $fix) {
            $roaster = $this->findByNameOrSlug($fix['match']);
            if (!$roaster) {
                $this->line(sprintf('   - skip (not found): %s', implode(' / ', $fix['match'])));
                continue;
            }

            // Compare every field we'd write — if all already match AND the
            // source is already 'manual' AND is_online_only is false (and
            // city override either absent or matches), the override is a
            // no-op (idempotent).
            $cityMatches = !isset($fix['city']) || $roaster->city === $fix['city'];
            $alreadyCorrect =
                $roaster->street_address === $fix['street_address']
                && $roaster->postal_code === $fix['postal_code']
                && (float) $roaster->latitude === (float) $fix['latitude']
                && (float) $roaster->longitude === (float) $fix['longitude']
                && $roaster->address_source === 'manual'
                && !$roaster->is_online_only
                && $cityMatches;

            if ($alreadyCorrect) {
                $this->line(sprintf('   = ok (already correct): %s', $roaster->name));
                continue;
            }

            $cityNote = isset($fix['city']) && $roaster->city !== $fix['city']
                ? sprintf(' [city: %s → %s]', $roaster->city, $fix['city'])
                : '';
            $this->line(sprintf(
                '   %s %s: %s — (%.4f, %.4f)%s',
                $dry ? '~' : '✓',
                $roaster->name,
                $fix['street_address'],
                $fix['latitude'],
                $fix['longitude'],
                $cityNote
            ));

            if (!$dry) {
                $payload = [
                    'street_address' => $fix['street_address'],
                    'postal_code' => $fix['postal_code'],
                    'latitude' => $fix['latitude'],
                    'longitude' => $fix['longitude'],
                    'address_source' => 'manual',
                    'address_verified_at' => now(),
                    'is_online_only' => false,
                ];
                if (isset($fix['city'])) {
                    $payload['city'] = $fix['city'];
                }
                $roaster->fill($payload)->save();
            }
            $n++;
        }

        return $n;
    }

    /**
     * E) Clear shipping_notes that contain scraped page-chrome (nav, footer,
     *    cookie banners). The old shipping-policy extractor took the first
     *    sentence containing "shipping", which on most templates matched the
     *    duplicated page title before any real content. Live audit found 35
     *    roasters with chrome-only notes. NULL'ing them lets the NEXT
     *    roasters:import-all run repopulate with the improved extractor.
     *
     * Idempotent — re-running won't touch rows whose notes are already
     * either null or chrome-free.
     */
    private function clearChromeShippingNotes(bool $dry): int
    {
        $this->line('E) Clear chrome-only shipping_notes');
        $chromeMarkers = [
            'Skip to content', 'Sign in', 'Sign Up', 'Passer au contenu',
            'Aller au contenu', 'Ignorer et passer', 'Facebook Instagram',
            'Copyright', 'All Rights Reserved', 'Powered by',
            'Politique d\'expédition', 'Politique d’expédition',
            'Politique de confidentialité',
        ];
        $query = Roaster::query()->whereNotNull('shipping_notes');
        $query->where(function ($q) use ($chromeMarkers) {
            foreach ($chromeMarkers as $marker) {
                $q->orWhere('shipping_notes', 'like', '%' . $marker . '%');
            }
        });

        $affected = $query->get(['id', 'name']);
        if ($affected->isEmpty()) {
            $this->line('   = no chrome-only shipping_notes found');
            return 0;
        }

        foreach ($affected as $r) {
            $this->line(sprintf('   %s %s: clearing chrome shipping_notes', $dry ? '~' : '✓', $r->name));
        }
        if (!$dry) {
            Roaster::whereIn('id', $affected->pluck('id'))->update(['shipping_notes' => null]);
        }
        return $affected->count();
    }

    /**
     * Resolve a roaster by any of the given labels, matched case-insensitively
     * against either the stored name or the slug. Mirrors the slug/name
     * lookup RoasterImporter uses so corrections and imports stay consistent.
     *
     * @param array<int, string> $labels
     */
    private function findByNameOrSlug(array $labels): ?Roaster
    {
        return Roaster::query()
            ->where(function ($q) use ($labels) {
                foreach ($labels as $label) {
                    $q->orWhereRaw('LOWER(name) = ?', [Str::lower($label)])
                      ->orWhere('slug', Str::slug($label));
                }
            })
            ->orderBy('id')
            ->first();
    }
}
