<?php

namespace Tests\Feature;

use App\Models\Roaster;
use App\Services\AddressQualityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Trust#6: address-quality classification + the read-only
 * `roasters:check-addresses` command.
 */
class AddressQualityCheckerTest extends TestCase
{
    use RefreshDatabase;

    private function make(string $slug, array $extra): Roaster
    {
        return Roaster::create(array_merge([
            'name' => 'R ' . $slug, 'slug' => $slug, 'city' => 'Vancouver', 'region' => 'BC',
            'website' => "https://{$slug}.example.com", 'is_active' => true,
        ], $extra));
    }

    private function check(int $months = 12): array
    {
        return app(AddressQualityChecker::class)->check(Roaster::all(), $months);
    }

    public function test_classifies_each_address_quality_bucket(): void
    {
        $this->make('u', ['latitude' => null, 'longitude' => null]);
        $this->make('c', ['latitude' => 49.2, 'longitude' => -123.1, 'address_source' => null]);
        $this->make('ms', ['latitude' => 49.2, 'longitude' => -123.1, 'address_source' => 'osm', 'street_address' => null]);
        $this->make('mp', ['latitude' => 49.2, 'longitude' => -123.1, 'address_source' => 'jsonld', 'street_address' => '123 Main St', 'postal_code' => null]);
        $this->make('st', ['latitude' => 49.2, 'longitude' => -123.1, 'address_source' => 'jsonld', 'street_address' => '123 Main St', 'postal_code' => 'V6B 1A1', 'address_verified_at' => now()->subMonths(18)]);
        $this->make('ok', ['latitude' => 49.2, 'longitude' => -123.1, 'address_source' => 'jsonld', 'street_address' => '1 A St', 'postal_code' => 'V6B 1A1', 'address_verified_at' => now()->subMonths(2)]);
        $this->make('oo', ['is_online_only' => true]);

        $report = $this->check(12);

        $this->assertCount(1, $report['buckets']['unplaced']);
        $this->assertSame('u', $report['buckets']['unplaced'][0]['slug']);
        $this->assertCount(1, $report['buckets']['centroid_only']);
        $this->assertCount(1, $report['buckets']['missing_street']);
        $this->assertCount(1, $report['buckets']['missing_postal']);
        $this->assertCount(1, $report['buckets']['stale']);

        $this->assertSame(1, $report['ok']);
        $this->assertSame(1, $report['online_only']);
        $this->assertSame(5, $report['flagged']);
    }

    public function test_most_severe_bucket_wins_for_overlapping_issues(): void
    {
        // Has no coords AND no street AND no postal — but "unplaced" is the
        // single most-severe classification, so it lands there alone.
        $this->make('x', ['latitude' => null, 'longitude' => null, 'street_address' => null, 'postal_code' => null]);

        $report = $this->check();

        $this->assertCount(1, $report['buckets']['unplaced']);
        $this->assertCount(0, $report['buckets']['missing_street']);
        $this->assertCount(0, $report['buckets']['missing_postal']);
        $this->assertSame(1, $report['flagged']);
    }

    public function test_stale_window_is_configurable(): void
    {
        $this->make('st', ['latitude' => 49.2, 'longitude' => -123.1, 'address_source' => 'jsonld', 'street_address' => '123 Main St', 'postal_code' => 'V6B 1A1', 'address_verified_at' => now()->subMonths(18)]);

        // 18-month-old verification is stale at a 12-month window, fresh at 24.
        $this->assertCount(1, $this->check(12)['buckets']['stale']);
        $this->assertCount(0, $this->check(24)['buckets']['stale']);
        $this->assertSame(1, $this->check(24)['ok']);
    }

    public function test_null_verified_at_on_complete_row_is_flagged_stale(): void
    {
        // A complete legacy row that was never timestamped still needs review.
        $this->make('legacy', ['latitude' => 49.2, 'longitude' => -123.1, 'address_source' => 'manual', 'street_address' => '5 Old St', 'postal_code' => 'V6B 1A1', 'address_verified_at' => null]);

        $this->assertCount(1, $this->check()['buckets']['stale']);
    }

    public function test_command_summarizes_flag_count(): void
    {
        $this->make('u', ['latitude' => null, 'longitude' => null]);

        $this->artisan('roasters:check-addresses')
            ->expectsOutputToContain('1 flagged')
            ->assertExitCode(0);
    }

    public function test_command_reports_all_clear_when_no_issues(): void
    {
        $this->make('ok', ['latitude' => 49.2, 'longitude' => -123.1, 'address_source' => 'jsonld', 'street_address' => '1 A St', 'postal_code' => 'V6B 1A1', 'address_verified_at' => now()]);

        $this->artisan('roasters:check-addresses')
            ->expectsOutputToContain('All physical roasters have complete')
            ->assertExitCode(0);
    }
}
