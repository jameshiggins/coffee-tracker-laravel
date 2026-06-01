<?php

namespace Tests\Feature;

use App\Models\SystemHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ops monitoring: the GET /up health probe. 200 when infra (database +
 * scheduler liveness) is healthy, 503 when an uptime monitor should page.
 * Data-quality signals (imports, mail) are reported but never flip to 503.
 */
class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_healthy_with_a_fresh_scheduler_tick(): void
    {
        SystemHeartbeat::ping('scheduler.tick');

        $this->getJson('/up')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.scheduler.ok', true)
            ->assertJsonStructure([
                'ok', 'status', 'time',
                'checks' => ['database', 'scheduler', 'mail', 'imports'],
            ]);
    }

    public function test_treats_a_never_ticked_scheduler_as_pending_not_failing(): void
    {
        // Fresh DB: no scheduler.tick row yet (e.g. the very first boot).
        $this->getJson('/up')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('checks.scheduler.detail', 'pending');
    }

    public function test_returns_503_when_the_scheduler_has_gone_stale(): void
    {
        SystemHeartbeat::create([
            'key' => 'scheduler.tick',
            'last_seen_at' => Carbon::now()->subMinutes(30),
        ]);

        $this->getJson('/up')
            ->assertStatus(503)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.scheduler.ok', false)
            ->assertJsonPath('checks.scheduler.detail', 'stale');
    }

    public function test_mail_check_surfaces_the_last_sent_timestamp(): void
    {
        SystemHeartbeat::ping('mail.sent');

        $this->getJson('/up')
            ->assertOk()
            ->assertJsonPath('checks.mail.ok', true);

        $this->assertNotNull(SystemHeartbeat::lastSeen('mail.sent'));
    }
}
