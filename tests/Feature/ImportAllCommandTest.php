<?php

namespace Tests\Feature;

use App\Models\Coffee;
use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ImportAllCommandTest extends TestCase
{
    use RefreshDatabase;

    private function shopifyResponse(string $beanName): array
    {
        return [
            'products' => [[
                'id' => 1, 'title' => $beanName, 'product_type' => 'Coffee', 'tags' => [],
                'body_html' => '',
                'variants' => [['id' => 11, 'title' => '250g', 'price' => '20.00', 'available' => true]],
            ]],
        ];
    }

    public function test_command_imports_each_active_roaster_with_a_website(): void
    {
        Roaster::create(['name' => 'Alpha', 'slug' => 'alpha', 'city' => 'X',
            'website' => 'https://alpha.example.com', 'is_active' => true, 'has_shipping' => true]);
        Roaster::create(['name' => 'Beta', 'slug' => 'beta', 'city' => 'Y',
            'website' => 'https://beta.example.com', 'is_active' => true, 'has_shipping' => true]);

        Http::fake([
            'alpha.example.com/*' => Http::response($this->shopifyResponse('Alpha Bean'), 200),
            'beta.example.com/*' => Http::response($this->shopifyResponse('Beta Bean'), 200),
        ]);

        $this->artisan('roasters:import-all')
            ->expectsOutputToContain('imported')
            ->assertExitCode(0);

        $this->assertSame(2, Coffee::count());
    }

    public function test_command_exits_nonzero_when_every_roaster_fails(): void
    {
        // Total failure = systemic (network down / bad deploy). The command must
        // exit non-zero so the scheduler's emailOutputOnFailure pages, instead
        // of a broken nightly import hiding behind a green exit code.
        Roaster::create(['name' => 'Alpha', 'slug' => 'alpha', 'city' => 'X',
            'website' => 'https://alpha.example.com', 'is_active' => true, 'has_shipping' => true]);
        Roaster::create(['name' => 'Beta', 'slug' => 'beta', 'city' => 'Y',
            'website' => 'https://beta.example.com', 'is_active' => true, 'has_shipping' => true]);

        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('network down')]);

        $this->artisan('roasters:import-all')->assertExitCode(1);
    }

    public function test_command_stays_green_when_at_least_one_roaster_succeeds(): void
    {
        // Individual dead roasters are normal — a partial failure is still a
        // successful run (the ops email itemizes the failures).
        Roaster::create(['name' => 'Alpha', 'slug' => 'alpha', 'city' => 'X',
            'website' => 'https://alpha.example.com', 'is_active' => true, 'has_shipping' => true]);
        Roaster::create(['name' => 'Beta', 'slug' => 'beta', 'city' => 'Y',
            'website' => 'https://beta.example.com', 'is_active' => true, 'has_shipping' => true]);

        Http::fake([
            'alpha.example.com/*' => Http::response($this->shopifyResponse('Alpha Bean'), 200),
            'beta.example.com/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('dead'),
        ]);

        $this->artisan('roasters:import-all')->assertExitCode(0);
    }

    public function test_command_skips_roasters_without_a_website(): void
    {
        Roaster::create(['name' => 'NoSite', 'slug' => 'nosite', 'city' => 'X', 'website' => null,
            'is_active' => true]);

        Http::fake();

        $this->artisan('roasters:import-all')->assertExitCode(0);
        $this->assertSame(0, Coffee::count());
    }

    public function test_command_skips_inactive_roasters(): void
    {
        Roaster::create(['name' => 'Inactive', 'slug' => 'inactive', 'city' => 'X',
            'website' => 'https://inactive.example.com', 'is_active' => false]);

        Http::fake();
        $this->artisan('roasters:import-all')->assertExitCode(0);
        $this->assertSame(0, Coffee::count());
    }

    public function test_command_continues_when_a_single_roaster_fails(): void
    {
        Roaster::create(['name' => 'OK', 'slug' => 'ok', 'city' => 'X',
            'website' => 'https://ok.example.com', 'is_active' => true]);
        Roaster::create(['name' => 'Bad', 'slug' => 'bad', 'city' => 'Y',
            'website' => 'https://bad.example.com', 'is_active' => true]);

        Http::fake([
            'ok.example.com/*' => Http::response($this->shopifyResponse('OK Bean'), 200),
            'bad.example.com/*' => Http::response('not found', 404),
        ]);

        $this->artisan('roasters:import-all')->assertExitCode(0);

        // OK roaster should have its bean; Bad should still be present but with no coffees.
        $this->assertSame(1, Coffee::count());
        $this->assertSame(1, Roaster::find(Roaster::where('slug', 'ok')->value('id'))->coffees()->count());
        $this->assertSame(0, Roaster::find(Roaster::where('slug', 'bad')->value('id'))->coffees()->count());
    }

    public function test_only_flag_filters_to_a_single_roaster_by_slug(): void
    {
        Roaster::create(['name' => 'Wanted', 'slug' => 'wanted', 'city' => 'X',
            'website' => 'https://wanted.example.com', 'is_active' => true]);
        Roaster::create(['name' => 'Other', 'slug' => 'other', 'city' => 'Y',
            'website' => 'https://other.example.com', 'is_active' => true]);

        Http::fake([
            'wanted.example.com/*' => Http::response($this->shopifyResponse('Wanted Bean'), 200),
            'other.example.com/*' => Http::response($this->shopifyResponse('Other Bean'), 200),
        ]);

        $this->artisan('roasters:import-all', ['--only' => 'wanted'])->assertExitCode(0);

        $this->assertSame(1, Coffee::count());
        $this->assertSame(1, Roaster::where('slug', 'wanted')->value('id') !== null
            ? Roaster::where('slug', 'wanted')->first()->coffees()->count() : 0);
    }

    public function test_rate_limited_roasters_are_paused_deferred_and_retried_at_the_end(): void
    {
        \Illuminate\Support\Sleep::fake();
        Roaster::create(['name' => 'Alpha', 'slug' => 'alpha', 'city' => 'X', 'platform' => 'shopify',
            'website' => 'https://alpha.example.com', 'is_active' => true, 'has_shipping' => true]);
        Roaster::create(['name' => 'Beta', 'slug' => 'beta', 'city' => 'Y', 'platform' => 'shopify',
            'website' => 'https://beta.example.com', 'is_active' => true, 'has_shipping' => true]);

        // Beta is throttled for its first attempt (the scraper's own 3 tries),
        // then answers normally when the command comes back to it.
        $betaCalls = 0;
        Http::fake([
            'alpha.example.com/*' => Http::response($this->shopifyResponse('Alpha Bean'), 200),
            'beta.example.com/*' => function () use (&$betaCalls) {
                $betaCalls++;

                return $betaCalls <= 3
                    ? Http::response('', 429, ['Retry-After' => '5'])
                    : Http::response($this->shopifyResponse('Beta Bean'), 200);
            },
        ]);

        $this->artisan('roasters:import-all')
            ->expectsOutputToContain('rate limited — deferred')
            ->expectsOutputToContain('Retrying 1 rate-limited roaster(s)')
            ->expectsOutputToContain('Done: 2 imported, 0 failed, 0 skipped (rate limited).')
            ->assertExitCode(0);

        $this->assertSame(2, Coffee::count(), 'both roasters imported in the end');
        $beta = Roaster::where('slug', 'beta')->first();
        $this->assertSame('success', $beta->last_import_status);
        $this->assertNull($beta->import_failing_since);

        // The run stood back once for the limiter window (plus the scraper's own
        // Retry-After waits), and never wrote an "import failed" log for Beta.
        \Illuminate\Support\Sleep::assertSlept(fn ($d) => $d->totalSeconds === \App\Console\Commands\ImportAllRoasters::RATE_LIMIT_PAUSE_SECONDS, 1);
        $this->assertDatabaseMissing('admin_logs', ['event' => 'import.roaster.failed']);
        $this->assertDatabaseHas('admin_logs', ['event' => 'import.roaster.rate_limited']);
    }

    public function test_a_roaster_still_throttled_on_the_second_pass_is_skipped_not_failed(): void
    {
        \Illuminate\Support\Sleep::fake();
        Roaster::create(['name' => 'Alpha', 'slug' => 'alpha', 'city' => 'X', 'platform' => 'shopify',
            'website' => 'https://alpha.example.com', 'is_active' => true, 'has_shipping' => true]);
        $beta = Roaster::create(['name' => 'Beta', 'slug' => 'beta', 'city' => 'Y', 'platform' => 'shopify',
            'website' => 'https://beta.example.com', 'is_active' => true, 'has_shipping' => true,
            'last_import_status' => 'success', 'last_imported_at' => now()->subDay()]);

        Http::fake([
            'alpha.example.com/*' => Http::response($this->shopifyResponse('Alpha Bean'), 200),
            'beta.example.com/*' => Http::response('', 429, ['Retry-After' => '5']),
        ]);

        $this->artisan('roasters:import-all')
            ->expectsOutputToContain('still rate limited — skipped')
            ->expectsOutputToContain('Done: 1 imported, 0 failed, 1 skipped (rate limited).')
            ->assertExitCode(0);

        $this->assertSame('success', $beta->fresh()->last_import_status, "yesterday's verdict stands");
    }
}
