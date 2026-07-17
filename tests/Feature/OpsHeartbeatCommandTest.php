<?php

namespace Tests\Feature;

use App\Models\SystemHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ops:heartbeat seeds scheduler.tick at container boot so /up doesn't
 * false-alarm before schedule:work's first tick — if this command breaks,
 * every fresh deploy reports unhealthy.
 */
class OpsHeartbeatCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_a_heartbeat_for_the_given_key(): void
    {
        $this->assertNull(SystemHeartbeat::lastSeen('scheduler.tick'));

        $this->artisan('ops:heartbeat', ['key' => 'scheduler.tick'])
            ->expectsOutputToContain('scheduler.tick')
            ->assertExitCode(0);

        $this->assertNotNull(SystemHeartbeat::lastSeen('scheduler.tick'));
    }

    public function test_repeat_runs_bump_the_existing_row_instead_of_duplicating(): void
    {
        SystemHeartbeat::create([
            'key' => 'scheduler.tick',
            'last_seen_at' => Carbon::now()->subHour(),
        ]);

        $this->artisan('ops:heartbeat', ['key' => 'scheduler.tick'])->assertExitCode(0);

        $this->assertDatabaseCount('system_heartbeats', 1);
        $this->assertTrue(
            SystemHeartbeat::lastSeen('scheduler.tick')->gt(Carbon::now()->subMinutes(5)),
            'repeat ping must move last_seen_at forward'
        );
    }
}
