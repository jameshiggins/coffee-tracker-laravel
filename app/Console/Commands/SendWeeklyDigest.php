<?php

namespace App\Console\Commands;

use App\Mail\WeeklyDataQualityDigest;
use App\Services\DataQualityReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Trust#2: build the weekly data-quality report and email it to the ops
 * address. Composes the per-concern audits (imports, sanity-gate drops,
 * duplicates, address gaps) into one digest.
 *
 * Schedule: weeklyOn(1, '13:00') — Monday 13:00 UTC (≈ 06:00 PST), after
 * the daily import (11:00 UTC) so the week opens on a settled snapshot.
 *
 * Read-only: --dry-run prints the JSON report instead of sending.
 */
class SendWeeklyDigest extends Command
{
    protected $signature = 'reports:weekly-digest
                            {--dry-run : Print the report as JSON instead of emailing it}
                            {--email= : Override the recipient address}
                            {--stale-days=7 : Treat a roaster as stale after this many days without a clean import}';

    protected $description = 'Email the weekly data-quality digest: import health, dropped variants, likely duplicates, address gaps.';

    public function handle(DataQualityReport $reporter): int
    {
        $report = $reporter->build((int) $this->option('stale-days'));

        if ($this->option('dry-run')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->newLine();
            $this->info($reporter->hasIssues($report)
                ? 'Dry run: report has issues an operator should review.'
                : 'Dry run: directory is clean — nothing to flag.');

            return self::SUCCESS;
        }

        $recipient = $this->option('email') ?: config('mail.from.address');

        if (empty($recipient)) {
            $this->error('No recipient: pass --email or set mail.from.address.');

            return self::FAILURE;
        }

        Mail::to($recipient)->send(new WeeklyDataQualityDigest($report));

        $this->info($reporter->hasIssues($report)
            ? "Sent data-quality digest to {$recipient} (issues flagged)."
            : "Sent data-quality digest to {$recipient} (all clear).");

        return self::SUCCESS;
    }
}
