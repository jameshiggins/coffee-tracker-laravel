<?php

namespace App\Console\Commands;

use App\Mail\DailyOpsSummary;
use App\Services\DailyOpsReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Ops notifications: build the daily ops summary and email it to the ops
 * address. Covers the four signals an operator asked to be told about —
 * roasters added, import errors, dropped variants, and mail delivery — over a
 * rolling window (default 24h).
 *
 * Schedule: dailyAt('11:30') — 30 min after the daily import (11:00 UTC) so the
 * import's outcome is captured. By default it sends every day; the reliable
 * daily arrival is itself the "scheduler + mail are alive" signal, and its
 * absence is the alarm (backstopped by the GET /up uptime monitor). Pass
 * --only-when-notable to suppress all-clear days.
 *
 * Read-only: --dry-run prints the JSON report instead of sending.
 */
class SendDailyOpsSummary extends Command
{
    protected $signature = 'reports:daily-ops
                            {--dry-run : Print the report as JSON instead of emailing it}
                            {--email= : Override the recipient address}
                            {--window-hours=24 : How many hours back to count roasters added}
                            {--only-when-notable : Skip sending on all-clear days}';

    protected $description = 'Email the daily ops summary: roasters added, import errors, dropped variants, mail delivery.';

    public function handle(DailyOpsReport $reporter): int
    {
        $report = $reporter->build((int) $this->option('window-hours'));
        $notable = $reporter->isNotable($report);

        if ($this->option('dry-run')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->newLine();
            $this->info($notable
                ? 'Dry run: notable activity an operator should review.'
                : 'Dry run: all clear — nothing notable.');

            return self::SUCCESS;
        }

        if ($this->option('only-when-notable') && ! $notable) {
            $this->info("Nothing notable in the last {$report['window_hours']}h; skipping send (--only-when-notable).");

            return self::SUCCESS;
        }

        $recipient = $this->option('email') ?: config('mail.from.address');

        if (empty($recipient)) {
            $this->error('No recipient: pass --email or set mail.from.address.');

            return self::FAILURE;
        }

        Mail::to($recipient)->send(new DailyOpsSummary($report, $notable));

        $this->info($notable
            ? "Sent daily ops summary to {$recipient} (action needed)."
            : "Sent daily ops summary to {$recipient} (all clear).");

        return self::SUCCESS;
    }
}
