<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Blade operator console (/admin/*) is gated by BasicAdminAuth, which is
 * the ONLY data-mutation surface in the app and previously had zero tests.
 * These lock in the fail-closed contract: no credentials configured = nobody
 * in; wrong credentials = 401; correct credentials = through.
 */
class AdminAccessControlTest extends TestCase
{
    use RefreshDatabase;

    private function basic(string $user, string $pass): array
    {
        return ['Authorization' => 'Basic ' . base64_encode("{$user}:{$pass}")];
    }

    public function test_admin_is_locked_out_when_credentials_are_not_configured(): void
    {
        // Default test env has no ADMIN_USER / ADMIN_PASS — must fail closed.
        config(['admin.user' => null, 'admin.pass' => null]);

        $this->get('/admin/roasters')->assertStatus(503);
        $this->get('/admin/moderation')->assertStatus(503);
    }

    public function test_admin_rejects_missing_credentials_with_401(): void
    {
        config(['admin.user' => 'operator', 'admin.pass' => 'sekret']);

        $this->get('/admin/roasters')
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Basic realm="Roastmap Admin", charset="UTF-8"');
    }

    public function test_admin_rejects_wrong_credentials_with_401(): void
    {
        config(['admin.user' => 'operator', 'admin.pass' => 'sekret']);

        $this->withHeaders($this->basic('operator', 'wrong'))
            ->get('/admin/roasters')
            ->assertStatus(401);

        $this->withHeaders($this->basic('nope', 'sekret'))
            ->get('/admin/roasters')
            ->assertStatus(401);
    }

    public function test_admin_allows_correct_credentials(): void
    {
        config(['admin.user' => 'operator', 'admin.pass' => 'sekret']);

        $this->withHeaders($this->basic('operator', 'sekret'))
            ->get('/admin/roasters')
            ->assertOk();
    }
}
