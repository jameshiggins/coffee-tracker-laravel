<?php

namespace Tests\Feature;

use App\Models\AdminLog;
use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * roasters:auto-deactivate-dead hides roasters whose domain has been
 * unresolvable for the whole window — and NOTHING else.
 */
class AutoDeactivateDeadRoastersTest extends TestCase
{
    use RefreshDatabase;

    private function roaster(array $attrs): Roaster
    {
        return Roaster::factory()->create(array_merge(['is_active' => true], $attrs));
    }

    public function test_deactivates_dead_domains_past_the_window(): void
    {
        $dead = $this->roaster([
            'name' => 'Long Dead', 'last_import_status' => 'error',
            'last_import_error' => 'cURL error 6: Could not resolve host: gone.test',
            'import_failing_since' => Carbon::now()->subDays(8),
        ]);

        $this->artisan('roasters:auto-deactivate-dead')->assertExitCode(0);

        $this->assertFalse($dead->fresh()->is_active);
        $this->assertSame(1, AdminLog::where('event', 'import.roaster.auto_deactivated')->count());
    }

    public function test_leaves_recent_failures_and_non_dns_errors_alone(): void
    {
        $recent = $this->roaster([
            'name' => 'Recently Dead', 'last_import_status' => 'error',
            'last_import_error' => 'Could not resolve host: x.test',
            'import_failing_since' => Carbon::now()->subDays(2), // under the window
        ]);
        $blocked = $this->roaster([
            'name' => 'Blocked', 'last_import_status' => 'error',
            'last_import_error' => 'fetch failed: 401',
            'import_failing_since' => Carbon::now()->subDays(20), // past the DNS window, inside the 30-day blocked window
        ]);
        $empty = $this->roaster([
            'name' => 'Empty', 'last_import_status' => 'empty',
            'last_imported_at' => now(), 'import_failing_since' => null,
        ]);

        $this->artisan('roasters:auto-deactivate-dead')->assertExitCode(0);

        $this->assertTrue($recent->fresh()->is_active, 'inside the window → kept');
        $this->assertTrue($blocked->fresh()->is_active, '401 for 20 days is inside the blocked window → kept');
        $this->assertTrue($empty->fresh()->is_active, 'empty catalog means the site is alive → kept');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $dead = $this->roaster([
            'last_import_status' => 'error',
            'last_import_error' => 'Could not resolve host: gone.test',
            'import_failing_since' => Carbon::now()->subDays(10),
        ]);

        $this->artisan('roasters:auto-deactivate-dead --dry-run')->assertExitCode(0);

        $this->assertTrue($dead->fresh()->is_active);
        $this->assertSame(0, AdminLog::where('event', 'import.roaster.auto_deactivated')->count());
    }

    public function test_deactivates_blocked_roasters_past_the_longer_window(): void
    {
        // Two storefronts answered 401 to every nightly import for seven weeks
        // and were listed in the ops email every single morning. A shop that has
        // refused every visitor for a month is not selling to anyone.
        $blocked = $this->roaster([
            'name' => 'Walled Off', 'last_import_status' => 'error',
            'last_import_error' => 'Shopify fetch failed: 401 for https://walled.test/products.json',
            'import_failing_since' => Carbon::now()->subDays(31),
        ]);
        $slow = $this->roaster([
            'name' => 'Slow', 'last_import_status' => 'error',
            'last_import_error' => 'cURL error 28: Connection timed out after 10002 milliseconds',
            'import_failing_since' => Carbon::now()->subDays(60), // alive, just slow → never auto-hidden
        ]);
        $throttled = $this->roaster([
            'name' => 'Throttled', 'last_import_status' => 'error',
            'last_import_error' => 'Shopify fetch failed: 429 for https://t.test/products.json',
            'import_failing_since' => Carbon::now()->subDays(60), // our problem, not theirs
        ]);

        $this->artisan('roasters:auto-deactivate-dead')
            ->expectsOutputToContain('Walled Off (storefront refusing us (401/403), failing since')
            ->assertExitCode(0);

        $this->assertFalse($blocked->fresh()->is_active);
        $this->assertTrue($slow->fresh()->is_active);
        $this->assertTrue($throttled->fresh()->is_active);

        $log = AdminLog::where('event', 'import.roaster.auto_deactivated')->firstOrFail();
        $this->assertStringContainsString('30+ days', $log->message);
        $this->assertSame('blocked', $log->context['kind']);
    }

    public function test_blocked_window_is_configurable(): void
    {
        $blocked = $this->roaster([
            'last_import_status' => 'error', 'last_import_error' => 'fetch failed: 403',
            'import_failing_since' => Carbon::now()->subDays(10),
        ]);

        $this->artisan('roasters:auto-deactivate-dead --blocked-days=7')->assertExitCode(0);

        $this->assertFalse($blocked->fresh()->is_active);
    }
}
