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
        // CDNs. ~2-minute total runtime for ~35 roasters; failures are
        // recorded per-roaster in last_import_status / last_import_error.
        $schedule->command('roasters:import-all')
            ->dailyAt('11:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->runInBackground();
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
