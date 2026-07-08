<?php

namespace App\Console\Commands;

use App\Models\AdminLog;
use App\Models\Roaster;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Auto-hide roasters whose website has been unreachable (DNS won't resolve)
 * for the whole retention window. A domain that fails to resolve every night
 * for a week is gone — closed, rebranded, or the domain lapsed — and just
 * errors forever otherwise, cluttering the directory and the daily digest.
 *
 * Deliberately narrow: ONLY the dead_domain error kind (not 401 bot-blocks,
 * which are often transient, and not empty catalogs, which mean the site is
 * alive). Deactivation is a SOFT hide (is_active=false) — every coffee,
 * tasting, and wishlist row is preserved and a later successful re-import
 * (or a manual toggle) brings the roaster straight back.
 *
 * Scheduled daily after the import; run manually with --days / --dry-run.
 */
class AutoDeactivateDeadRoasters extends Command
{
    protected $signature = 'roasters:auto-deactivate-dead
                            {--days=7 : Deactivate roasters whose domain has failed DNS this many days}
                            {--dry-run : List what would be deactivated without changing anything}';

    protected $description = 'Deactivate active roasters whose domain has been unresolvable for the retention window.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = Carbon::now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        // Cheap pre-filter in SQL; classify the exact error kind in PHP so the
        // logic stays identical to Roaster::importErrorKind().
        $candidates = Roaster::query()
            ->where('is_active', true)
            ->where('last_import_status', 'error')
            ->whereNotNull('import_failing_since')
            ->where('import_failing_since', '<=', $cutoff)
            ->get()
            ->filter(fn (Roaster $r) => $r->importErrorKind() === 'dead_domain');

        if ($candidates->isEmpty()) {
            $this->info('No dead-domain roasters past the '.$days.'-day window.');

            return self::SUCCESS;
        }

        foreach ($candidates as $roaster) {
            $since = $roaster->import_failing_since?->toDateString();
            $this->line(($dryRun ? '[dry-run] ' : '')."Deactivating {$roaster->name} (failing since {$since})");

            if ($dryRun) {
                continue;
            }

            $roaster->update(['is_active' => false]);
            AdminLog::warning('import.roaster.auto_deactivated',
                "Auto-deactivated {$roaster->name}: domain unreachable {$days}+ days", [
                    'roaster_id' => $roaster->id,
                    'website' => $roaster->website,
                    'failing_since' => $since,
                ]);
        }

        $verb = $dryRun ? 'Would deactivate' : 'Deactivated';
        $this->info("{$verb} {$candidates->count()} dead-domain roaster(s).");

        return self::SUCCESS;
    }
}
