<?php

namespace App\Console\Commands;

use App\Models\AdminLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Keep the admin_logs table bounded — it shares the single SQLite volume
 * with everything else, and verbose imports can write thousands of rows a
 * night. Age-pruned daily (Kernel schedule); --days overrides for manual
 * runs.
 */
class PruneAdminLogs extends Command
{
    protected $signature = 'logs:prune {--days=14 : Delete admin_logs rows older than this many days}';

    protected $description = 'Delete admin_logs rows older than the retention window.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = Carbon::now()->subDays($days);

        $deleted = AdminLog::query()->where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} admin log rows older than {$days} days.");

        if ($deleted > 0) {
            AdminLog::info('logs.pruned', "Pruned {$deleted} admin log rows older than {$days} days.");
        }

        return self::SUCCESS;
    }
}
