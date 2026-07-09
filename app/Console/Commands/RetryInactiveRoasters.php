<?php

namespace App\Console\Commands;

use App\Models\AdminLog;
use App\Models\Roaster;
use App\Services\RoasterImporter;
use Illuminate\Console\Command;

/**
 * Give deactivated roasters a periodic second chance.
 *
 * AutoDeactivateDeadRoasters soft-hides a roaster whose domain has failed DNS
 * for a week (is_active=false); the daily import only touches active roasters,
 * so once hidden a roaster is never retried — even if the site comes back a
 * month later. This command re-attempts the import for every inactive roaster
 * and REACTIVATES the ones whose storefront is genuinely back (a real catalog).
 *
 * Reactivation is careful because RoasterImporter::import() optimistically flips
 * is_active=true BEFORE it fetches. So:
 *   - fetch succeeds with beans  → leave it reactivated (the site is back);
 *   - fetch succeeds but EMPTY   → re-hide it (alive but nothing to show yet),
 *                                  so a parked/holding page doesn't repopulate
 *                                  the directory with a beanless roaster;
 *   - fetch throws (still dead)  → re-hide it, undoing import()'s optimistic flip.
 *
 * Scheduled weekly on the weekend (see App\Console\Kernel). Dry-run by default-
 * safe --dry-run lists candidates without any HTTP or writes.
 */
class RetryInactiveRoasters extends Command
{
    protected $signature = 'roasters:retry-inactive
                            {--dry-run : List the inactive roasters that would be retried without fetching}';

    protected $description = 'Re-attempt import for deactivated roasters and reactivate the ones whose site is back.';

    public function handle(RoasterImporter $importer): int
    {
        $inactive = Roaster::query()
            ->where('is_active', false)
            ->whereNotNull('website')
            ->orderBy('name')
            ->get();

        if ($inactive->isEmpty()) {
            $this->info('No inactive roasters with a website to retry.');

            return self::SUCCESS;
        }

        $this->info("Retrying {$inactive->count()} inactive roaster(s).");

        if ($this->option('dry-run')) {
            foreach ($inactive as $r) {
                $this->line("  • {$r->name} — {$r->website}");
            }
            $this->warn('Dry run — nothing fetched.');

            return self::SUCCESS;
        }

        $recovered = 0;
        $stillDown = 0;
        $empty = 0;

        foreach ($inactive as $r) {
            try {
                $imported = $importer->import($r->website, name: $r->name, city: $r->city, region: $r->region);
                $count = $imported->coffees()->count();

                if ($count > 0) {
                    // import() already set is_active=true — the site is back.
                    $recovered++;
                    $this->line(sprintf('  ✓ %-40s back with %d beans (reactivated)', $r->name, $count));
                    AdminLog::info('import.roaster.reactivated',
                        "Reactivated {$r->name}: domain back with {$count} beans", [
                            'roaster_id' => $r->id, 'website' => $r->website, 'coffees' => $count,
                        ]);
                } else {
                    // Alive but empty — keep it hidden and retry again next week.
                    Roaster::whereKey($r->id)->update(['is_active' => false]);
                    $empty++;
                    $this->line(sprintf('  – %-40s responded but empty; still hidden', $r->name));
                }
            } catch (\Throwable $e) {
                // import() optimistically flipped is_active=true before the
                // fetch failed — undo it so a still-dead site stays hidden.
                Roaster::whereKey($r->id)->update(['is_active' => false]);
                $stillDown++;
                $this->line(sprintf('  ✗ %-40s %s', $r->name, $this->shortReason($e->getMessage())));
            }
        }

        $this->newLine();
        $this->info("Done: {$recovered} reactivated, {$empty} still empty, {$stillDown} still failing.");

        return self::SUCCESS;
    }

    private function shortReason(string $msg): string
    {
        $first = strtok($msg, "\n");

        return strlen($first) > 100 ? substr($first, 0, 97).'…' : $first;
    }
}
