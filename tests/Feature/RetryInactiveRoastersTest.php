<?php

namespace Tests\Feature;

use App\Models\Roaster;
use App\Services\RoasterImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The weekend second-chance sweep for deactivated roasters. RoasterImporter is
 * mocked so we test the command's reactivate/re-hide decision — including that
 * it undoes import()'s optimistic is_active=true flip when the site is still
 * dead or empty — without real HTTP.
 */
class RetryInactiveRoastersTest extends TestCase
{
    use RefreshDatabase;

    private function inactiveRoaster(string $slug): Roaster
    {
        return Roaster::create([
            'name' => ucfirst($slug).' Coffee',
            'slug' => $slug,
            'city' => 'Vancouver',
            'region' => 'BC',
            'website' => "https://{$slug}.example.com",
            'is_active' => false,
            'last_import_status' => 'error',
            'import_failing_since' => now()->subDays(30),
        ]);
    }

    public function test_reactivates_a_roaster_whose_site_is_back_with_beans(): void
    {
        $active = Roaster::create([
            'name' => 'Live Coffee', 'slug' => 'live', 'city' => 'Calgary', 'region' => 'AB',
            'website' => 'https://live.example.com', 'is_active' => true,
        ]);
        $r = $this->inactiveRoaster('back');
        $r->coffees()->create(['name' => 'Yirgacheffe', 'origin' => 'Ethiopia']);

        // import() is expected exactly once — for the inactive roaster only,
        // proving active roasters are left to the daily import.
        $this->mock(RoasterImporter::class, function ($m) use ($r) {
            $m->shouldReceive('import')->once()->andReturnUsing(function () use ($r) {
                Roaster::whereKey($r->id)->update(['is_active' => true]); // import()'s optimistic flip
                return $r->fresh('coffees');
            });
        });

        $this->artisan('roasters:retry-inactive')
            ->expectsOutputToContain('reactivated')
            ->assertExitCode(0);

        $this->assertTrue($r->fresh()->is_active, 'a recovered roaster is reactivated');
        $this->assertTrue($active->fresh()->is_active);
    }

    public function test_rehides_a_roaster_that_is_still_dead(): void
    {
        $r = $this->inactiveRoaster('dead');

        $this->mock(RoasterImporter::class, function ($m) use ($r) {
            $m->shouldReceive('import')->once()->andReturnUsing(function () use ($r) {
                Roaster::whereKey($r->id)->update(['is_active' => true]); // optimistic flip before the failure
                throw new \RuntimeException('could not resolve host');
            });
        });

        $this->artisan('roasters:retry-inactive')->assertExitCode(0);

        $this->assertFalse($r->fresh()->is_active, 'a still-dead roaster is re-hidden');
    }

    public function test_rehides_a_roaster_that_responds_but_is_empty(): void
    {
        $r = $this->inactiveRoaster('empty'); // no coffees attached

        $this->mock(RoasterImporter::class, function ($m) use ($r) {
            $m->shouldReceive('import')->once()->andReturnUsing(function () use ($r) {
                Roaster::whereKey($r->id)->update(['is_active' => true]);
                return $r->fresh();
            });
        });

        $this->artisan('roasters:retry-inactive')->assertExitCode(0);

        $this->assertFalse($r->fresh()->is_active, 'alive-but-empty stays hidden and gets retried next week');
    }

    public function test_dry_run_lists_without_fetching_or_changing_anything(): void
    {
        $r = $this->inactiveRoaster('dry');

        $this->mock(RoasterImporter::class, function ($m) {
            $m->shouldNotReceive('import');
        });

        $this->artisan('roasters:retry-inactive', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertFalse($r->fresh()->is_active);
    }

    public function test_no_inactive_roasters_is_a_clean_noop(): void
    {
        Roaster::create([
            'name' => 'Only Active', 'slug' => 'only-active', 'city' => 'Toronto', 'region' => 'ON',
            'website' => 'https://only-active.example.com', 'is_active' => true,
        ]);

        $this->mock(RoasterImporter::class, function ($m) {
            $m->shouldNotReceive('import');
        });

        $this->artisan('roasters:retry-inactive')
            ->expectsOutputToContain('No inactive roasters')
            ->assertExitCode(0);
    }
}
