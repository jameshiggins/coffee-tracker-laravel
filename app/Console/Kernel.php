<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Q6: daily inventory + price refresh from every roaster's source.
        // 04:00 PST (= 11:00 UTC) — quiet hour for both NA and EU storefront
        // CDNs. Roughly ~10 minutes across the full directory; failures are
        // recorded per-roaster in last_import_status / last_import_error.
        $import = $schedule->command('roasters:import-all')
            ->dailyAt('11:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground()
            ->emailOutputOnFailure(env('CRON_FAILURE_EMAIL', config('mail.from.address')));
        $this->pingIfConfigured($import, 'import');

        // Q14: email users about wishlisted beans that came back in stock,
        // ~3 hours after the import finishes so the deltas are settled.
        $schedule->command('alerts:restock')
            ->dailyAt('14:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->emailOutputOnFailure(env('CRON_FAILURE_EMAIL', config('mail.from.address')));

        // Q-AR: monthly address-resolution sweep. Addresses rarely change, so
        // a once-a-month cascade is plenty — running daily would spam roaster
        // sites and Nominatim for no real benefit. 12:00 UTC = 05:00 PST on
        // the 1st of each month, well clear of the daily import at 11:00 UTC.
        // Already-resolved roasters are skipped without --force, so this run
        // is small after the first sweep.
        $schedule->command('roasters:scrape-addresses')
            ->monthlyOn(1, '12:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->emailOutputOnFailure(env('CRON_FAILURE_EMAIL', config('mail.from.address')));

        // Trust#2: weekly ops digest rolling up import health, sanity-gate
        // drops, likely duplicates, and address gaps into one email. Monday
        // 13:00 UTC (≈ 06:00 PST), after the daily import so the week opens
        // on a settled snapshot. Read-only — it only reports.
        $digest = $schedule->command('reports:weekly-digest')
            ->weeklyOn(1, '13:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->emailOutputOnFailure(env('CRON_FAILURE_EMAIL', config('mail.from.address')));
        $this->pingIfConfigured($digest, 'digest');

        // Ops notifications: daily ops summary covering roasters added, import
        // errors, dropped variants, and mail delivery. 11:30 UTC (≈ 04:30 PST),
        // 30 min after the daily import so today's outcome is captured, and well
        // clear of restock alerts (14:00) and the weekly digest (Mon 13:00).
        // Sends every day — the reliable arrival is itself the "mail + scheduler
        // are alive" signal, and its absence is the alarm (backstopped by /up).
        $schedule->command('reports:daily-ops')
            ->dailyAt('11:30')
            ->withoutOverlapping()
            ->onOneServer()
            ->emailOutputOnFailure(env('CRON_FAILURE_EMAIL', config('mail.from.address')));

        // Data quality: sweep the active catalog for rows the coffee/gear
        // filter now rejects and soft-remove them. A re-import already
        // self-heals each roaster, but this backstops rows that slipped in
        // before the filter was tightened on roasters that have since stopped
        // being imported (the "Espro French Press" class of junk). Weekly,
        // after the Monday digest; soft-remove is reversible and tasting-bearing
        // rows are left for manual review.
        $schedule->command('coffees:purge-non-coffee --apply')
            ->weeklyOn(1, '13:30')
            ->withoutOverlapping()
            ->onOneServer()
            ->emailOutputOnFailure(env('CRON_FAILURE_EMAIL', config('mail.from.address')));

        // Ops liveness: bump the scheduler heartbeat that GET /up reads. If
        // schedule:work dies, this stops and /up flips to 503 within ~15 min,
        // so whatever uptime monitor watches /up catches a dead scheduler —
        // not just a dead web server. Cheap (one upsert) and single-server.
        $schedule->call(fn () => \App\Models\SystemHeartbeat::ping('scheduler.tick'))
            ->everyFiveMinutes()
            ->name('scheduler-heartbeat')
            ->withoutOverlapping();
    }

    /**
     * Attach healthchecks.io-style pings to a scheduled event when its URL is
     * configured (config/services.php → healthchecks.*). No-op until the URL
     * is set, so local/dev runs stay quiet. Pings the base URL on success and
     * {url}/fail on failure — the healthchecks.io / Better Stack convention.
     */
    private function pingIfConfigured(\Illuminate\Console\Scheduling\Event $event, string $key): void
    {
        $url = config("services.healthchecks.{$key}");

        if (is_string($url) && $url !== '') {
            $event->pingOnSuccess($url)->pingOnFailure(rtrim($url, '/').'/fail');
        }
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
