<?php

namespace Tests\Feature;

use App\Models\AdminLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * logs:prune retention. A prune command with an inverted comparison deletes
 * the wrong half of the table, so the boundary gets explicit coverage.
 */
class PruneAdminLogsTest extends TestCase
{
    use RefreshDatabase;

    private function logRow(Carbon $createdAt): AdminLog
    {
        return AdminLog::create([
            'level' => 'info',
            'event' => 'test.event',
            'message' => 'row',
            'created_at' => $createdAt,
        ]);
    }

    public function test_prunes_rows_older_than_default_retention_and_keeps_recent(): void
    {
        $old = $this->logRow(Carbon::now()->subDays(30));
        $fresh = $this->logRow(Carbon::now()->subDays(2));

        $this->artisan('logs:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('admin_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('admin_logs', ['id' => $fresh->id]);

        // The prune itself lands in the audit trail when rows were deleted.
        $this->assertDatabaseHas('admin_logs', ['event' => 'logs.pruned']);
    }

    public function test_days_option_overrides_retention_window(): void
    {
        $row = $this->logRow(Carbon::now()->subDays(30));

        $this->artisan('logs:prune', ['--days' => 60])->assertExitCode(0);

        $this->assertDatabaseHas('admin_logs', ['id' => $row->id]);
    }

    public function test_noop_run_does_not_write_an_audit_row(): void
    {
        $this->logRow(Carbon::now()->subDays(1));

        $this->artisan('logs:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('admin_logs', ['event' => 'logs.pruned']);
    }

    public function test_days_floor_is_one_day(): void
    {
        // --days=0 must not mean "delete everything"; it clamps to 1 day.
        $recent = $this->logRow(Carbon::now()->subHours(2));
        $old = $this->logRow(Carbon::now()->subDays(3));

        $this->artisan('logs:prune', ['--days' => 0])->assertExitCode(0);

        $this->assertDatabaseHas('admin_logs', ['id' => $recent->id]);
        $this->assertDatabaseMissing('admin_logs', ['id' => $old->id]);
    }
}
