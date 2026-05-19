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
