<?php

namespace App\Providers;

use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureSqliteForConcurrency();
    }

    /**
     * SQLite concurrency hardening (2026-07 review P2). Three processes write
     * this database concurrently in prod (Apache, schedule:work, queue:work);
     * the default rollback journal makes writers block readers, so long
     * import bursts surface as "database is locked" 500s. WAL lets readers
     * proceed during writes; busy_timeout makes contending writers wait
     * instead of failing instantly.
     *
     * Applied on connect (not in config) because Laravel 10's sqlite config
     * has no journal_mode/busy_timeout keys. :memory: databases (the test
     * suite) skip WAL — it is meaningless there — but keep busy_timeout.
     */
    private function configureSqliteForConcurrency(): void
    {
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $connection = $event->connection;

            if ($connection->getDriverName() !== 'sqlite') {
                return;
            }

            $connection->statement('PRAGMA busy_timeout = 5000;');

            // WAL conversion is opt-in via DB_SQLITE_WAL (set in fly.toml)
            // and NEVER runs in tests/CI: converting needs an exclusive lock
            // and proved genuinely unsafe under a test runner's many
            // concurrent short-lived connections (CI hit both "database is
            // locked" and "disk image is malformed"). In prod the flag is on
            // and the first boot connection — the entrypoint's sequential
            // `migrate`, before Apache/worker/scheduler exist — performs the
            // one conversion; journal_mode is persistent, so every later
            // connection reads 'wal' and skips.
            if (! config('database.sqlite_wal') || $connection->getDatabaseName() === ':memory:') {
                return;
            }

            try {
                $mode = $connection->selectOne('PRAGMA journal_mode')->journal_mode ?? '';
                if (strtolower($mode) !== 'wal') {
                    $connection->statement('PRAGMA journal_mode = WAL;');
                }
            } catch (\Throwable) {
                // Best-effort: a locked DB keeps its current journal mode;
                // busy_timeout above is the essential part.
            }
        });
    }
}
