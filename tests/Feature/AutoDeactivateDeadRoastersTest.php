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
            'import_failing_since' => Carbon::now()->subDays(30), // old, but not DNS
        ]);
        $empty = $this->roaster([
            'name' => 'Empty', 'last_import_status' => 'empty',
            'last_imported_at' => now(), 'import_failing_since' => null,
        ]);

        $this->artisan('roasters:auto-deactivate-dead')->assertExitCode(0);

        $this->assertTrue($recent->fresh()->is_active, 'inside the window → kept');
        $this->assertTrue($blocked->fresh()->is_active, '401 is not a dead domain → kept');
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
}
