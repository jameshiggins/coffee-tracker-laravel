<?php

namespace Tests\Feature;

use App\Mail\WeeklyDataQualityDigest;
use App\Models\Roaster;
use App\Models\ScraperRejectionLog;
use App\Services\DataQualityReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Trust#2: the weekly ops digest — DataQualityReport aggregation, the
 * WeeklyDataQualityDigest mailable, and the reports:weekly-digest command.
 *
 * The aggregation composes four already-unit-tested concerns (imports,
 * sanity-gate rejections, duplicates, address gaps) that all scan
 * is_active=true roasters, so the fixtures below keep every roaster
 * address-complete and uniquely named UNLESS a row is meant to trip a
 * specific signal — that way each top-level count is deterministic.
 */
class WeeklyDataQualityDigestTest extends TestCase
{
    use RefreshDatabase;

    /** A fully clean, map-complete, freshly-imported active roaster. Override per concern. */
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
            'latitude' => 49.2,
            'longitude' => -123.1,
            'address_source' => 'jsonld',
            'street_address' => '1 Main St',
            'postal_code' => 'V6B 1A1',
            'address_verified_at' => now(),
            'last_import_status' => 'success',
            'last_imported_at' => now(),
        ], $extra));
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

    /** Builds a deliberately messy directory and returns the report. */
    private function messyReport(): array
    {
        $alpha = $this->roaster('alpha', 'Alpha Coffee');                                  // success + fresh
        $bravo = $this->roaster('bravo', 'Bravo Coffee', ['last_import_status' => 'empty']);
        $this->roaster('charlie', 'Charlie Coffee', ['last_import_status' => 'error']);
        $this->roaster('delta', 'Delta Coffee', ['last_import_status' => null, 'last_imported_at' => null]); // never
        $this->roaster('echo', 'Echo Coffee', ['last_imported_at' => now()->subDays(30)]);  // success but stale
        $this->roaster('foxtrot', 'Foxtrot Coffee', ['is_online_only' => true]);            // address-excluded

        // Identical canonical name ("pilot") across two distinct hosts → one name group.
        $this->roaster('pilot-a', 'Pilot Coffee Roasters', ['website' => 'https://pilotcoffeeroasters.com']);
        $this->roaster('pilot-b', 'Pilot Coffee', ['website' => 'https://pilot.example.ca']);

        // No coordinates and not online-only → one "unplaced" address flag.
        $this->roaster('unplaced', 'Unplaced Coffee', ['latitude' => null, 'longitude' => null]);

        // An inactive, totally-broken row that must not influence any count.
        $this->roaster('ghost', 'Ghost Coffee', [
            'is_active' => false, 'last_import_status' => 'error', 'last_imported_at' => null,
            'latitude' => null, 'longitude' => null,
        ]);

        // Three currently-outstanding sanity-gate drops: alpha ×2, bravo ×1.
        $this->reject($alpha, ScraperRejectionLog::REASON_PRICE_NON_POSITIVE);
        $this->reject($alpha, ScraperRejectionLog::REASON_CPG_OUT_OF_BAND);
        $this->reject($bravo, ScraperRejectionLog::REASON_CPG_OUT_OF_BAND);

        return app(DataQualityReport::class)->build(7);
    }

    public function test_report_aggregates_every_concern(): void
    {
        $report = $this->messyReport();

        // Imports — note "success" and "stale" are independent axes: echo is both.
        $this->assertSame(9, $report['imports']['total']);   // ghost (inactive) excluded
        $this->assertSame(6, $report['imports']['success']); // alpha, echo, foxtrot, pilot-a, pilot-b, unplaced
        $this->assertSame(1, $report['imports']['empty']);
        $this->assertSame(1, $report['imports']['error']);
        $this->assertSame(1, $report['imports']['never']);
        $this->assertSame(1, $report['imports']['stale']);   // echo only

        // Rejections (current snapshot).
        $this->assertSame(3, $report['rejections']['total']);
        $this->assertSame(1, $report['rejections']['by_reason']['price_non_positive']);
        $this->assertSame(2, $report['rejections']['by_reason']['cpg_out_of_band']);
        $this->assertCount(2, $report['rejections']['top_roasters']);
        $this->assertSame(2, $report['rejections']['top_roasters'][0]['count']); // worst offender first

        // Duplicates.
        $this->assertSame(0, $report['duplicates']['host_groups']);
        $this->assertSame(1, $report['duplicates']['name_groups']);
        $this->assertSame(0, $report['duplicates']['similar_pairs']);

        // Addresses — foxtrot online-only excluded; unplaced flagged; the rest OK.
        $this->assertSame(1, $report['addresses']['flagged']);
        $this->assertSame(1, $report['addresses']['buckets']['unplaced']);
        $this->assertSame(1, $report['addresses']['online_only']);
        $this->assertSame(7, $report['addresses']['ok']);

        $this->assertTrue(app(DataQualityReport::class)->hasIssues($report));
    }

    public function test_clean_directory_has_no_issues(): void
    {
        $this->roaster('mono', 'Monogram Coffee', ['website' => 'https://monogramcoffee.com']);
        $this->roaster('rosso', 'Rosso Coffee Roasters', ['website' => 'https://rossocoffee.com']);

        $reporter = app(DataQualityReport::class);
        $report = $reporter->build(7);

        $this->assertFalse($reporter->hasIssues($report));
        $this->assertSame(0, $report['imports']['error']);
        $this->assertSame(0, $report['imports']['empty']);
        $this->assertSame(0, $report['imports']['stale']);
        $this->assertSame(0, $report['imports']['never']);
        $this->assertSame(0, $report['rejections']['total']);
        $this->assertSame(0, $report['duplicates']['name_groups']);
        $this->assertSame(0, $report['addresses']['flagged']);
    }

    public function test_mailable_renders_for_both_states(): void
    {
        $issues = (new WeeklyDataQualityDigest($this->messyReport()))->render();
        $this->assertStringContainsString('data-quality digest', $issues);
        $this->assertStringContainsString('Possible duplicates', $issues);

        // Reset for a clean render.
        Roaster::query()->delete();
        ScraperRejectionLog::query()->delete();
        $this->roaster('solo', 'Solo Coffee');

        $clean = (new WeeklyDataQualityDigest(app(DataQualityReport::class)->build(7)))->render();
        $this->assertStringContainsString('complete, current addresses', $clean);
    }

    public function test_command_sends_the_digest(): void
    {
        Mail::fake();
        $this->messyReport();

        $this->artisan('reports:weekly-digest')
            ->expectsOutputToContain('issues flagged')
            ->assertExitCode(0);

        Mail::assertQueued(WeeklyDataQualityDigest::class);
    }

    public function test_command_honors_email_override(): void
    {
        Mail::fake();
        $this->roaster('solo', 'Solo Coffee');

        $this->artisan('reports:weekly-digest', ['--email' => 'ops@roastmap.ca'])
            ->expectsOutputToContain('all clear')
            ->assertExitCode(0);

        Mail::assertQueued(WeeklyDataQualityDigest::class, fn ($mail) => $mail->hasTo('ops@roastmap.ca'));
    }

    public function test_dry_run_prints_without_sending(): void
    {
        Mail::fake();
        $this->messyReport();

        $this->artisan('reports:weekly-digest', ['--dry-run' => true])
            ->expectsOutputToContain('"imports"')
            ->assertExitCode(0);

        Mail::assertNothingQueued();
    }
}
