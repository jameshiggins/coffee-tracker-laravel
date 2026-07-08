<?php

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The admin login page (replaces the HTTP Basic browser prompt): verifies
 * the env credential with hash_equals, throttles failures per IP, sets the
 * session flag AdminSessionAuth checks, and supports logout.
 */
class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['admin.user' => 'operator', 'admin.pass' => 'sekret']);
        RateLimiter::clear('admin-login:127.0.0.1');
    }

    public function test_login_page_renders_with_a_csrf_form(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Roastmap Admin')
            ->assertSee('name="username"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="_token"', false);
    }

    public function test_correct_credentials_start_a_session_and_redirect_to_the_intended_page(): void
    {
        // Hitting a gated page first records the intended URL…
        $this->get('/admin/moderation')->assertRedirect(route('admin.login'));

        // …and a successful login lands there, not on the generic home.
        $this->post('/admin/login', ['username' => 'operator', 'password' => 'sekret'])
            ->assertRedirect('/admin/moderation');

        $this->assertTrue(session('admin_authenticated'));
        $this->get('/admin/roasters')->assertOk();
    }

    public function test_wrong_credentials_bounce_back_with_an_error_and_no_session(): void
    {
        $this->from('/admin/login')
            ->post('/admin/login', ['username' => 'operator', 'password' => 'wrong'])
            ->assertRedirect('/admin/login')
            ->assertSessionHasErrors('auth');

        $this->assertNull(session('admin_authenticated'));
    }

    public function test_failures_are_throttled_and_even_correct_credentials_wait_out_the_cooldown(): void
    {
        foreach (range(1, 10) as $i) {
            $this->post('/admin/login', ['username' => 'operator', 'password' => "wrong-{$i}"]);
        }

        // 11th attempt — with the RIGHT password — is refused during cooldown:
        // the throttle is checked before the credentials.
        $this->from('/admin/login')
            ->post('/admin/login', ['username' => 'operator', 'password' => 'sekret'])
            ->assertRedirect('/admin/login')
            ->assertSessionHasErrors('auth');
        $this->assertNull(session('admin_authenticated'));
        $this->assertStringContainsString(
            'Too many failed attempts',
            session('errors')->first('auth')
        );
    }

    public function test_successful_login_clears_the_failure_counter(): void
    {
        foreach (range(1, 9) as $i) {
            $this->post('/admin/login', ['username' => 'operator', 'password' => "wrong-{$i}"]);
        }

        $this->post('/admin/login', ['username' => 'operator', 'password' => 'sekret'])
            ->assertRedirect(route('admin.roasters.index'));

        // Counter reset: a later stray failure doesn't instantly re-lock.
        $this->assertSame(0, RateLimiter::attempts('admin-login:127.0.0.1'));
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAsAdmin();

        $this->post('/admin/logout')->assertRedirect(route('admin.login'));
        $this->get('/admin/roasters')->assertRedirect(route('admin.login'));
    }

    public function test_an_already_authenticated_operator_skips_the_login_page(): void
    {
        $this->actingAsAdmin()
            ->get('/admin/login')
            ->assertRedirect(route('admin.roasters.index'));
    }
}
