<?php

namespace Tests\Feature;

use App\Mail\DailyOpsSummary;
use App\Models\Roaster;
use App\Models\ScraperRejectionLog;
use App\Models\SystemHeartbeat;
use App\Services\DailyOpsReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Ops notifications: the daily ops summary — DailyOpsReport aggregation, the
 * DailyOpsSummary mailable, and the reports:daily-ops command.
 *
 * Like the weekly digest test, fixtures keep each signal independent so the
 * top-level counts are deterministic: "infrastructure" roasters (the failing
 * one, the rejection sources) are backdated outside the added-window so they
 * don't also inflate roasters_added, and only the rows meant to count as
 * "added" are created fresh.
 */
class DailyOpsSummaryTest extends TestCase
{
    use RefreshDatabase;

    /** A clean, active, freshly-imported roaster created "now". Override per concern. */
    private function roaster(string $slug, string $name, array $extra = []): Roaster
    {
        return Roaster::create(array_merge([
            'name' => $name,
            'slug' => $slug,
            'city' => 'Vancouver',
            'region' => 'BC',
            'website' => "https://{$slug}.example.com",
            'is_active' => true,
            'is_online_only' => false,
            'last_import_status' => 'success',
            'last_imported_at' => now(),
        ], $extra));
    }

    /** Move a roaster's created_at outside the added-window (DB write avoids timestamp magic). */
    private function backdate(Roaster $r, int $hours): void
    {
        DB::table('roasters')->where('id', $r->id)->update(['created_at' => now()->subHours($hours)]);
    }

    private function reject(Roaster $r, string $reason): void
    {
        ScraperRejectionLog::create([
            'roaster_id' => $r->id,
            'coffee_id' => null,
            'coffee_name' => 'Some Bean',
            'reason' => $reason,
            'context' => ['note' => 'test'],
        ]);
    }

    /** Builds a directory with one of every signal and returns the report. */
    private function messyReport(): array
    {
        // Two fresh additions (inside the 24h window).
        $this->roaster('aroma', 'Aroma Coffee');
        $this->roaster('brew', 'Brew Coffee');

        // A currently-failing roaster, backdated so it doesn't count as "added".
        $broken = $this->roaster('crash', 'Crash Coffee', [
            'last_import_status' => 'error',
            'last_import_error' => 'HTTP 500 from storefront feed',
        ]);
        $this->backdate($broken, 48);

        // An inactive failing roaster must NOT count (matches /up's is_active filter).
        $ghost = $this->roaster('ghost', 'Ghost Coffee', [
            'is_active' => false,
            'last_import_status' => 'error',
            'last_import_error' => 'should be ignored',
        ]);
        $this->backdate($ghost, 48);

        // A rejection source, also backdated.
        $rej = $this->roaster('reject-source', 'Reject Source Coffee');
        $this->backdate($rej, 48);

        // Three outstanding sanity-gate drops: rej ×2, broken ×1.
        $this->reject($rej, ScraperRejectionLog::REASON_PRICE_NON_POSITIVE);
        $this->reject($rej, ScraperRejectionLog::REASON_CPG_OUT_OF_BAND);
        $this->reject($broken, ScraperRejectionLog::REASON_CPG_OUT_OF_BAND);

        // Mail confirmed flowing.
        SystemHeartbeat::ping('mail.sent');

        return app(DailyOpsReport::class)->build(24);
    }

    public function test_report_aggregates_every_signal(): void
    {
        $report = $this->messyReport();

        // Added — only the two fresh roasters; backdated ones excluded.
        $this->assertSame(2, $report['roasters_added']['count']);
        $addedSlugs = array_column($report['roasters_added']['list'], 'slug');
        $this->assertContains('aroma', $addedSlugs);
        $this->assertContains('brew', $addedSlugs);
        $this->assertNotContains('crash', $addedSlugs);

        // Import errors — active failing only; inactive ghost excluded; message carried.
        $this->assertSame(1, $report['import_errors']['count']);
        $this->assertSame('Crash Coffee', $report['import_errors']['list'][0]['name']);
        $this->assertStringContainsString('HTTP 500', $report['import_errors']['list'][0]['error']);

        // Rejections (current snapshot).
        $this->assertSame(3, $report['rejections']['total']);
        $this->assertSame(1, $report['rejections']['by_reason']['price_non_positive']);
        $this->assertSame(2, $report['rejections']['by_reason']['cpg_out_of_band']);
        $this->assertSame(2, $report['rejections']['top_roasters'][0]['count']); // worst offender first

        // Mail — confirmed flowing.
        $this->assertTrue($report['mail']['healthy']);
        $this->assertNotNull($report['mail']['last_sent']);

        $this->assertTrue(app(DailyOpsReport::class)->isNotable($report));
    }

    public function test_added_respects_the_window(): void
    {
        $this->roaster('inside', 'Inside Coffee');
        $old = $this->roaster('outside', 'Outside Coffee');
        $this->backdate($old, 48);

        $report = app(DailyOpsReport::class)->build(24);

        $this->assertSame(1, $report['roasters_added']['count']);
        $this->assertSame('inside', $report['roasters_added']['list'][0]['slug']);
    }

    public function test_clean_directory_is_not_notable(): void
    {
        $solo = $this->roaster('solo', 'Solo Coffee');
        $this->backdate($solo, 48); // not even "added"
        SystemHeartbeat::ping('mail.sent');

        $reporter = app(DailyOpsReport::class);
        $report = $reporter->build(24);

        $this->assertFalse($reporter->isNotable($report));
        $this->assertSame(0, $report['roasters_added']['count']);
        $this->assertSame(0, $report['import_errors']['count']);
        $this->assertSame(0, $report['rejections']['total']);
        $this->assertTrue($report['mail']['healthy']);
    }

    public function test_mail_states(): void
    {
        $reporter = app(DailyOpsReport::class);

        // Never sent.
        $this->assertNull($reporter->build(24)['mail']['last_sent']);
        $this->assertFalse($reporter->build(24)['mail']['healthy']);

        // Stale: last send older than the healthy window.
        SystemHeartbeat::create(['key' => 'mail.sent', 'last_seen_at' => now()->subHours(48)]);
        $stale = $reporter->build(24)['mail'];
        $this->assertNotNull($stale['last_sent']);
        $this->assertFalse($stale['healthy']);

        // Fresh: just sent.
        SystemHeartbeat::ping('mail.sent');
        $this->assertTrue($reporter->build(24)['mail']['healthy']);
    }

    public function test_mailable_renders_for_both_states(): void
    {
        $notableReport = $this->messyReport();
        $issues = (new DailyOpsSummary($notableReport, true))->render();
        $this->assertStringContainsString('Roastmap daily ops', $issues);
        $this->assertStringContainsString('New roasters', $issues);
        $this->assertStringContainsString('Crash Coffee', $issues);
        $this->assertStringContainsString('failing their import', $issues);

        // Reset to a clean state for the all-clear render.
        Roaster::query()->delete();
        ScraperRejectionLog::query()->delete();
        SystemHeartbeat::query()->delete();
        $solo = $this->roaster('solo', 'Solo Coffee');
        $this->backdate($solo, 48);
        SystemHeartbeat::ping('mail.sent');

        $cleanReport = app(DailyOpsReport::class)->build(24);
        $clean = (new DailyOpsSummary($cleanReport, false))->render();
        $this->assertStringContainsString('nothing needs attention', $clean);
        $this->assertStringContainsString('No roasters added', $clean);
        $this->assertStringContainsString('Working', $clean);
    }

    public function test_command_sends_the_summary(): void
    {
        Mail::fake();
        $this->messyReport();

        $this->artisan('reports:daily-ops')
            ->expectsOutputToContain('action needed')
            ->assertExitCode(0);

        Mail::assertSent(DailyOpsSummary::class);
    }

    public function test_command_honors_email_override(): void
    {
        Mail::fake();
        $solo = $this->roaster('solo', 'Solo Coffee');
        $this->backdate($solo, 48);
        SystemHeartbeat::ping('mail.sent');

        $this->artisan('reports:daily-ops', ['--email' => 'ops@roastmap.ca'])
            ->expectsOutputToContain('all clear')
            ->assertExitCode(0);

        Mail::assertSent(DailyOpsSummary::class, fn ($mail) => $mail->hasTo('ops@roastmap.ca'));
    }

    public function test_only_when_notable_skips_a_clean_day(): void
    {
        Mail::fake();
        $solo = $this->roaster('solo', 'Solo Coffee');
        $this->backdate($solo, 48);
        SystemHeartbeat::ping('mail.sent');

        $this->artisan('reports:daily-ops', ['--only-when-notable' => true])
            ->expectsOutputToContain('skipping send')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }

    public function test_only_when_notable_still_sends_when_notable(): void
    {
        Mail::fake();
        $this->messyReport();

        $this->artisan('reports:daily-ops', ['--only-when-notable' => true])
            ->assertExitCode(0);

        Mail::assertSent(DailyOpsSummary::class);
    }

    public function test_dry_run_prints_without_sending(): void
    {
        Mail::fake();
        $this->messyReport();

        $this->artisan('reports:daily-ops', ['--dry-run' => true])
            ->expectsOutputToContain('"roasters_added"')
            ->assertExitCode(0);

        Mail::assertNothingSent();
    }
}
