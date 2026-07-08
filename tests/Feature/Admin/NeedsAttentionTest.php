<?php

namespace Tests\Feature\Admin;

use App\Models\AdminLog;
use App\Models\Roaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NeedsAttentionTest extends TestCase
{
    use RefreshDatabase;

    private function roaster(array $attrs): Roaster
    {
        return Roaster::factory()->create(array_merge([
            'is_active' => true, 'website' => 'https://example.test',
        ], $attrs));
    }

    public function test_error_kind_classifies_dns_auth_and_other(): void
    {
        $dead = $this->roaster(['last_import_status' => 'error', 'last_import_error' => 'cURL error 6: Could not resolve host: gone.test']);
        $blocked = $this->roaster(['last_import_status' => 'error', 'last_import_error' => 'Shopify fetch failed: 401 for https://x.test']);
        $other = $this->roaster(['last_import_status' => 'error', 'last_import_error' => 'Timed out after 8s']);
        $ok = $this->roaster(['last_import_status' => 'success']);

        $this->assertSame('dead_domain', $dead->importErrorKind());
        $this->assertSame('blocked', $blocked->importErrorKind());
        $this->assertSame('error', $other->importErrorKind());
        $this->assertNull($ok->importErrorKind());
    }

    public function test_attention_page_groups_roasters_by_cause(): void
    {
        $this->roaster(['name' => 'Dead Co', 'last_import_status' => 'error', 'last_import_error' => 'Could not resolve host: dead.test']);
        $this->roaster(['name' => 'Blocked Co', 'last_import_status' => 'error', 'last_import_error' => 'fetch failed: 401']);
        $this->roaster(['name' => 'Empty Co', 'last_import_status' => 'empty', 'last_imported_at' => now()]);
        $this->roaster(['name' => 'Never Co', 'last_imported_at' => null, 'last_import_status' => null]);
        $this->roaster(['name' => 'Healthy Co', 'last_import_status' => 'success', 'last_imported_at' => now()]);

        $res = $this->actingAsAdmin()->get('/admin/attention')->assertOk();
        $res->assertSee('Dead Co')->assertSee('Blocked Co')->assertSee('Empty Co')->assertSee('Never Co');
        $res->assertSee('Dead domains')->assertSee('Blocked (401 / 403)')->assertSee('Empty catalog');
        // Healthy roasters aren't listed as needing attention.
        $res->assertDontSee('Healthy Co');
    }

    public function test_bulk_deactivate_dead_only_touches_dead_domains(): void
    {
        $dead = $this->roaster(['name' => 'Dead', 'last_import_status' => 'error', 'last_import_error' => 'Could not resolve host: dead.test']);
        $blocked = $this->roaster(['name' => 'Blocked', 'last_import_status' => 'error', 'last_import_error' => 'fetch failed: 401']);

        $this->actingAsAdmin()->post('/admin/attention/deactivate-dead')->assertRedirect(route('admin.attention.index'));

        $this->assertFalse($dead->fresh()->is_active, 'dead domain deactivated');
        $this->assertTrue($blocked->fresh()->is_active, 'blocked roaster untouched');
        $this->assertSame(1, AdminLog::where('event', 'admin.roaster.deactivated')->count());
    }

    public function test_retry_queues_an_import_job(): void
    {
        Queue::fake();
        $r = $this->roaster(['name' => 'Retry Me', 'website' => 'https://retry.test', 'last_import_status' => 'error']);

        $this->actingAsAdmin()->post("/admin/attention/{$r->slug}/retry")->assertRedirect();

        Queue::assertPushed(\App\Jobs\ImportRoasterJob::class);
    }

    public function test_attention_requires_admin_auth(): void
    {
        config(['admin.user' => 'operator', 'admin.pass' => 'sekret']);
        $this->get('/admin/attention')->assertRedirect(route('admin.login'));
    }
}
