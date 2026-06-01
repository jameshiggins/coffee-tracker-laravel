<?php

namespace App\Console\Commands;

use App\Models\SystemHeartbeat;
use Illuminate\Console\Command;

/**
 * Record a liveness heartbeat for the given signal. Used by the container
 * entrypoint to seed scheduler.tick at boot — so /up doesn't false-alarm in
 * the brief gap before schedule:work's first tick — and handy for a manual
 * ping while debugging.
 */
class OpsHeartbeat extends Command
{
    protected $signature = 'ops:heartbeat {key : The signal name to bump, e.g. scheduler.tick}';

    protected $description = 'Record a liveness heartbeat the /up health check reads.';

    public function handle(): int
    {
        $key = $this->argument('key');
        SystemHeartbeat::ping($key);
        $this->info("Heartbeat recorded: {$key}");

        return self::SUCCESS;
    }
}
