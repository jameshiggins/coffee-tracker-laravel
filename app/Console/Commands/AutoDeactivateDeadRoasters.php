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
 * Two kinds, two windows:
 *   dead_domain — 7 days. DNS failing every night for a week is gone.
 *   blocked     — 30 days. A 401/403 is often a transient bot-block, so it
 *                 gets a long leash; but a storefront that has refused every
 *                 visitor for a month (password page, closed shop) isn't
 *                 selling to anyone and just repeats in the ops email forever.
 * Never timeouts, rate limits or empty catalogs — those mean the site is alive.
 *
 * Deactivation is a SOFT hide (is_active=false) — every coffee, tasting, and
 * wishlist row is preserved and a later successful re-import (or a manual
 * toggle) brings the roaster straight back; roasters:retry-inactive gives
 * every hidden roaster a weekly second chance.
 *
 * Scheduled daily after the import; run manually with --days / --blocked-days
 * / --dry-run.
 */
class AutoDeactivateDeadRoasters extends Command
{
    protected $signature = 'roasters:auto-deactivate-dead
                            {--days=7 : Deactivate roasters whose domain has failed DNS this many days}
                            {--blocked-days=30 : Deactivate roasters that have answered 401/403 for this many days}
                            {--dry-run : List what would be deactivated without changing anything}';

    protected $description = 'Deactivate active roasters whose domain has been unresolvable (7d) or whose storefront has refused us (30d) for the whole window.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $blockedDays = max(1, (int) $this->option('blocked-days'));
        $dryRun = (bool) $this->option('dry-run');

        $windows = [
            'dead_domain' => ['days' => $days, 'cutoff' => Carbon::now()->subDays($days), 'why' => 'domain unreachable'],
            'blocked' => ['days' => $blockedDays, 'cutoff' => Carbon::now()->subDays($blockedDays), 'why' => 'storefront refusing us (401/403)'],
        ];
        $longest = max($windows['dead_domain']['cutoff'], $windows['blocked']['cutoff']);

        // Cheap pre-filter in SQL (anything failing at least the shorter
        // window); classify the exact error kind in PHP so the logic stays
        // identical to Roaster::importErrorKind(), then apply each kind's window.
        $candidates = Roaster::query()
            ->where('is_active', true)
            ->where('last_import_status', 'error')
            ->whereNotNull('import_failing_since')
            ->where('import_failing_since', '<=', $longest)
            ->get()
            ->filter(function (Roaster $r) use ($windows) {
                $kind = $r->importErrorKind();

                return isset($windows[$kind]) && $r->import_failing_since->lte($windows[$kind]['cutoff']);
            });

        if ($candidates->isEmpty()) {
            $this->info("No dead-domain roasters past the {$days}-day window, none blocked past the {$blockedDays}-day window.");

            return self::SUCCESS;
        }

        foreach ($candidates as $roaster) {
            $kind = $roaster->importErrorKind();
            $window = $windows[$kind];
            $since = $roaster->import_failing_since?->toDateString();
            $this->line(($dryRun ? '[dry-run] ' : '')."Deactivating {$roaster->name} ({$window['why']}, failing since {$since})");

            if ($dryRun) {
                continue;
            }

            $roaster->update(['is_active' => false]);
            AdminLog::warning('import.roaster.auto_deactivated',
                "Auto-deactivated {$roaster->name}: {$window['why']} {$window['days']}+ days", [
                    'roaster_id' => $roaster->id,
                    'website' => $roaster->website,
                    'kind' => $kind,
                    'failing_since' => $since,
                ]);
        }

        $verb = $dryRun ? 'Would deactivate' : 'Deactivated';
        $this->info("{$verb} {$candidates->count()} roaster(s).");

        return self::SUCCESS;
    }
}
