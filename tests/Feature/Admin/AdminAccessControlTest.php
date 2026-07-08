<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Blade operator console (/admin/*) is gated by AdminSessionAuth — the
 * ONLY data-mutation surface in the app. These lock in the contract:
 * no credentials configured = nobody in (fail closed); no session = bounced
 * to the login page; authenticated session = through.
 */
class AdminAccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_is_locked_out_when_credentials_are_not_configured(): void
    {
        // Default test env has no ADMIN_USER / ADMIN_PASS — must fail closed,
        // including the login page itself.
        config(['admin.user' => null, 'admin.pass' => null]);

        $this->get('/admin/roasters')->assertStatus(503);
        $this->get('/admin/moderation')->assertStatus(503);
        $this->get('/admin/login')->assertStatus(503);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        config(['admin.user' => 'operator', 'admin.pass' => 'sekret']);

        $this->get('/admin/roasters')->assertRedirect(route('admin.login'));
        $this->get('/admin/moderation')->assertRedirect(route('admin.login'));
    }

    public function test_a_forged_session_value_is_not_enough(): void
    {
        config(['admin.user' => 'operator', 'admin.pass' => 'sekret']);

        // Only the literal boolean set by the login controller passes.
        $this->withSession(['admin_authenticated' => 'yes'])
            ->get('/admin/roasters')
            ->assertRedirect(route('admin.login'));
    }

    public function test_an_authenticated_session_gets_through(): void
    {
        $this->actingAsAdmin()->get('/admin/roasters')->assertOk();
    }
}
