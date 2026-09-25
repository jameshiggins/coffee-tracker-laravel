<?php

namespace Tests\Feature\Admin;

use App\Models\AdminLog;
use App\Models\Roaster;
use App\Models\ScraperRejectionLog;
use App\Services\DailyOpsReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /admin/rejections — the operator's way to retire a sanity-gate drop that
 * turned out to be fine (a real 3 kg bag at 2.3¢/g) so it stops appearing in
 * the daily email.
 */
class AdminRejectionsTest extends TestCase
{
    use RefreshDatabase;

    private function drop(Roaster $r, string $name, string $suspected = ScraperRejectionLog::SUSPECT_BULK_PRICING): ScraperRejectionLog
    {
        return ScraperRejectionLog::create([
            'roaster_id' => $r->id, 'coffee_id' => null, 'coffee_name' => $name,
            'reason' => ScraperRejectionLog::REASON_CPG_OUT_OF_BAND,
            'context' => ['price' => 69, 'grams' => 3000, 'cpg' => 2.3, 'floor' => 1.5, 'source_size_label' => '3 kg', 'suspected' => $suspected],
            'first_seen_at' => now()->subDays(3),
        ]);
    }

    public function test_page_lists_open_drops_grouped_by_roaster_with_the_suspected_cause(): void
    {
        $pista = Roaster::factory()->create(['name' => 'Café Pista']);
        $peaks = Roaster::factory()->create(['name' => 'Peaks Coffee Company']);
        $this->drop($pista, 'Saison - Brésil / Guatemala');
        $this->drop($peaks, 'Blueberry Pancake Latte', ScraperRejectionLog::SUSPECT_NON_COFFEE);
        $this->drop($peaks, 'Already Checked')->update(['reviewed_at' => now()]);

        $res = $this->actingAsAdmin()->get('/admin/rejections')->assertOk();
        $res->assertSee('Café Pista')->assertSee('Saison - Brésil / Guatemala')
            ->assertSee('plausible bulk pricing')
            ->assertSee('Blueberry Pancake Latte')->assertSee('probably not coffee')
            ->assertSee('$69 / 3000 g = 2.3¢/g')
            ->assertSee('2 open')->assertSee('1 reviewed')
            ->assertSee('Already Checked')->assertSee('Re-open');
    }

    public function test_marking_reviewed_hides_the_row_from_the_daily_report_and_logs_it(): void
    {
        $r = Roaster::factory()->create(['name' => 'Café Pista']);
        $row = $this->drop($r, 'Saison - Brésil / Guatemala');
        $this->assertSame(1, app(DailyOpsReport::class)->build(24)['rejections']['total']);

        $this->actingAsAdmin()
            ->post("/admin/rejections/{$row->id}/review")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($row->fresh()->reviewed_at);
        $report = app(DailyOpsReport::class)->build(24)['rejections'];
        $this->assertSame(0, $report['total']);
        $this->assertSame(1, $report['reviewed']);
        $this->assertDatabaseHas('admin_logs', ['event' => 'admin.rejection.reviewed']);
    }

    public function test_reopening_brings_the_row_back(): void
    {
        $r = Roaster::factory()->create();
        $row = $this->drop($r, 'Fife Blend');
        $row->update(['reviewed_at' => now()]);

        $this->actingAsAdmin()->post("/admin/rejections/{$row->id}/unreview")->assertRedirect();

        $this->assertNull($row->fresh()->reviewed_at);
        $this->assertSame(1, app(DailyOpsReport::class)->build(24)['rejections']['total']);
        $this->assertSame(1, AdminLog::where('event', 'admin.rejection.unreviewed')->count());
    }

    public function test_requires_admin_auth(): void
    {
        config(['admin.user' => 'operator', 'admin.pass' => 'sekret']);
        $this->get('/admin/rejections')->assertRedirect(route('admin.login'));
        $r = Roaster::factory()->create();
        $row = $this->drop($r, 'X');
        $this->post("/admin/rejections/{$row->id}/review")->assertRedirect(route('admin.login'));
        $this->assertNull($row->fresh()->reviewed_at);
    }
}
