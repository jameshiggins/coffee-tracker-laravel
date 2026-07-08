<?php

namespace Tests\Feature\Admin;

use App\Models\AdminLog;
use App\Models\Roaster;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin logging subsystem: the verbose toggle gates debug rows, audit
 * events always land, the viewer filters, and admin actions are recorded.
 */
class AdminLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::forget('verbose_logging');
    }

    public function test_errors_warnings_and_info_are_always_recorded(): void
    {
        AdminLog::error('t.err', 'boom');
        AdminLog::warning('t.warn', 'careful');
        AdminLog::info('t.info', 'fyi');

        $this->assertSame(3, AdminLog::count());
    }

    public function test_debug_is_dropped_when_verbose_is_off_and_kept_when_on(): void
    {
        Setting::put('verbose_logging', '0');
        AdminLog::debug('t.dbg', 'noisy');
        $this->assertSame(0, AdminLog::count());

        Setting::put('verbose_logging', '1');
        AdminLog::debug('t.dbg', 'noisy');
        $this->assertSame(1, AdminLog::count());
    }

    public function test_a_failed_write_never_bubbles(): void
    {
        // Oversized message is truncated, not fatal; context survives.
        AdminLog::error('t.big', str_repeat('x', 5000), ['k' => 'v']);

        $row = AdminLog::first();
        $this->assertLessThanOrEqual(2000, mb_strlen($row->message));
        $this->assertSame(['k' => 'v'], $row->context);
    }

    public function test_viewer_filters_by_level_and_event_prefix(): void
    {
        AdminLog::error('import.roaster.failed', 'a');
        AdminLog::info('admin.roaster.created', 'b');
        AdminLog::warning('import.roaster.empty', 'c');

        $this->actingAsAdmin()->get('/admin/logs?level=error')
            ->assertOk()->assertSee('import.roaster.failed')->assertDontSee('admin.roaster.created');

        $this->actingAsAdmin()->get('/admin/logs?event=import.')
            ->assertOk()->assertSee('import.roaster.failed')->assertSee('import.roaster.empty')
            ->assertDontSee('admin.roaster.created');
    }

    public function test_toggle_flips_the_setting_and_records_an_audit_event(): void
    {
        $this->assertFalse(Setting::verboseLogging());

        $this->actingAsAdmin()->post('/admin/settings/verbose-logging')
            ->assertRedirect(route('admin.logs.index'));

        Setting::forget('verbose_logging');
        $this->assertTrue(Setting::verboseLogging());
        $this->assertSame(1, AdminLog::where('event', 'admin.settings.verbose_logging')->count());

        $this->actingAsAdmin()->post('/admin/settings/verbose-logging');
        Setting::forget('verbose_logging');
        $this->assertFalse(Setting::verboseLogging());
    }

    public function test_logs_page_requires_admin_auth(): void
    {
        config(['admin.user' => 'operator', 'admin.pass' => 'sekret']);
        $this->get('/admin/logs')->assertRedirect(route('admin.login'));
    }

    public function test_admin_roaster_actions_write_audit_rows(): void
    {
        $this->actingAsAdmin()->post('/admin/roasters', [
            'name' => 'Logged Roaster', 'region' => 'BC', 'city' => 'Victoria',
        ])->assertRedirect();

        $this->assertSame(1, AdminLog::where('event', 'admin.roaster.created')->count());

        $roaster = Roaster::where('name', 'Logged Roaster')->first();
        $this->actingAsAdmin()->delete("/admin/roasters/{$roaster->slug}")->assertRedirect();
        $this->assertSame(1, AdminLog::where('event', 'admin.roaster.deactivated')->count());
    }
}
