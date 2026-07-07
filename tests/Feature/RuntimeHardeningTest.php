<?php

namespace Tests\Feature;

use App\Jobs\ImportRoasterJob;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards for the 2026-07 review's runtime-hardening fixes: SQLite concurrency
 * pragmas (AppServiceProvider::configureSqliteForConcurrency) and the queue
 * visibility-timeout invariant.
 */
class RuntimeHardeningTest extends TestCase
{
    public function test_sqlite_connections_get_a_busy_timeout(): void
    {
        // The suite's :memory: connection goes through the same
        // ConnectionEstablished listener as prod's file DB.
        $row = DB::select('PRAGMA busy_timeout')[0];

        $this->assertSame(5000, (int) ($row->timeout ?? $row->busy_timeout ?? 0));
    }

    public function test_file_backed_sqlite_connections_run_in_wal_mode_when_opted_in(): void
    {
        // WAL conversion is opt-in via database.sqlite_wal (prod sets
        // DB_SQLITE_WAL=true in fly.toml; tests/CI leave it off because the
        // exclusive-lock conversion is unsafe under a test runner). Opt in
        // here and exercise the listener against a real temp file the way
        // prod's /data/database.sqlite connects on boot.
        config(['database.sqlite_wal' => true]);
        $path = tempnam(sys_get_temp_dir(), 'waltest');

        config(['database.connections.waltest' => [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);

        try {
            $mode = DB::connection('waltest')->select('PRAGMA journal_mode')[0]->journal_mode;

            $this->assertSame('wal', strtolower($mode));
        } finally {
            DB::purge('waltest');
            @unlink($path);
            @unlink($path.'-wal');
            @unlink($path.'-shm');
        }
    }

    public function test_queue_retry_after_exceeds_the_longest_job_timeout(): void
    {
        $job = new \ReflectionClass(ImportRoasterJob::class);
        $timeout = $job->newInstanceWithoutConstructor()->timeout;

        $this->assertGreaterThan(
            $timeout,
            config('queue.connections.database.retry_after'),
            'retry_after must exceed ImportRoasterJob::$timeout or a second worker re-reserves running imports (tries=1 → insta-fail).'
        );
    }
}
