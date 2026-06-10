<?php

namespace App\Console\Commands;

use App\Models\Coffee;
use App\Services\Scraping\Shared;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Sweep the active catalog for rows that are NOT coffee.
 *
 * Background: products are filtered by Shared::looksLikeCoffee() at import
 * time, and a re-import self-heals (a product the filter now rejects drops out
 * of the scraped set and gets soft-removed). But a row imported BEFORE the
 * gear/merch filter was tightened stays "active" until that roaster is next
 * scraped — and if a roaster stops being imported, the junk lingers forever.
 * "Espro French Press", branded mugs, milk pitchers, honey jars and internal
 * TEST SKUs all reached production this way.
 *
 * This command re-runs the current filter over every active coffee by name and
 * soft-removes the ones it no longer accepts — closing that gap without waiting
 * for a full re-scrape. Dry-run by default; pass --apply to act. Scheduled
 * weekly (see App\Console\Kernel) so the directory self-cleans even for
 * roasters that are no longer being re-imported.
 *
 * It is conservative and consistent with import: looksLikeCoffee(name) only
 * rejects names carrying explicit gear/merch/junk markers and default-accepts
 * everything else, so a normal bean is never purged. Soft-remove is reversible
 * (admin restore), and only rows with NO user tastings are touched.
 */
class PurgeNonCoffee extends Command
{
    protected $signature = 'coffees:purge-non-coffee
                            {--apply : Actually soft-remove the matches (default is a dry run)}';

    protected $description = 'Soft-remove active rows the coffee/gear filter now rejects (sweeps junk imported before the filter was tightened).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $matches = Coffee::with('roaster')
            ->withCount('tastings')
            ->whereNull('removed_at')
            ->get()
            ->filter(fn (Coffee $c) => ! Shared::looksLikeCoffee((string) $c->name, '', []));

        if ($matches->isEmpty()) {
            $this->info('No non-coffee rows in the active catalog. Nothing to do.');

            return self::SUCCESS;
        }

        // Never silently remove a row that carries user tastings — flag those
        // for a human instead of destroying the context behind a rating.
        [$withTastings, $clean] = $matches->partition(fn (Coffee $c) => $c->tastings_count > 0);

        $this->warn(($apply ? 'Soft-removing' : '[dry run] Would soft-remove') . ' ' . $clean->count() . ' non-coffee row(s):');
        $now = Carbon::now();
        foreach ($clean as $c) {
            $this->line(sprintf('  #%-6d %-26s %s', $c->id, Str::limit((string) ($c->roaster->name ?? '?'), 24), $c->name));
            if ($apply) {
                $c->update(['removed_at' => $now]);
            }
        }

        if ($withTastings->isNotEmpty()) {
            $this->newLine();
            $this->warn($withTastings->count() . ' non-coffee row(s) have user tastings and were LEFT for manual review:');
            foreach ($withTastings as $c) {
                $this->line(sprintf('  #%-6d %-26s %s  (%d tastings)', $c->id, Str::limit((string) ($c->roaster->name ?? '?'), 24), $c->name, $c->tastings_count));
            }
        }

        if (! $apply) {
            $this->newLine();
            $this->info('Dry run — re-run with --apply to soft-remove the listed rows.');
        }

        return self::SUCCESS;
    }
}
