<?php

namespace Tests\Feature\Admin;

use App\Jobs\ImportRoasterJob;
use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H7: the admin import/refresh actions must queue the heavy scrape rather than
 * run it inline on the web request (which could time out).
 */
class AdminImportDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin()
    {
        return $this->actingAsAdmin();
    }

    public function test_import_form_queues_an_import_job(): void
    {
        Queue::fake();

        $this->asAdmin()->post('/admin/import', [
            'url' => 'https://newroaster.test',
            'name' => 'New Roaster',
            'city' => 'Victoria',
            'region' => 'BC',
        ])->assertRedirect(route('admin.roasters.index'));

        Queue::assertPushed(ImportRoasterJob::class, function (ImportRoasterJob $job) {
            return $job->url === 'https://newroaster.test' && $job->name === 'New Roaster';
        });
    }

    public function test_refresh_queues_an_import_job_for_the_roaster_website(): void
    {
        Queue::fake();
        $roaster = Roaster::factory()->create(['website' => 'https://existing.test']);

        $this->asAdmin()
            ->post("/admin/roasters/{$roaster->slug}/refresh")
            ->assertRedirect();

        Queue::assertPushed(ImportRoasterJob::class, fn (ImportRoasterJob $job) => $job->url === 'https://existing.test');
    }
}
