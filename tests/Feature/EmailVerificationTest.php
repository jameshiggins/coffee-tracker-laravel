<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_dispatches_verification_email(): void
    {
        Notification::fake();

        $this->postJson('/api/auth/register', [
            'name' => 'Alice', 'email' => 'alice@example.com',
            'password' => 'secret-pw-123', 'password_confirmation' => 'secret-pw-123',
        ])->assertCreated()
            ->assertJsonPath('verification_email_sent', true);

        $user = User::where('email', 'alice@example.com')->first();
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_signed_url_marks_email_verified_and_redirects(): void
    {
        Event::fake();
        $user = User::create([
            'name' => 'A', 'email' => 'a@example.com',
            'display_name' => 'a_taster', 'password' => bcrypt('x'),
        ]);
        $this->assertNull($user->email_verified_at);

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ]);

        $r = $this->get($url);

        $r->assertStatus(302); // redirects to frontend /verified?ok=1
        $this->assertNotNull($user->fresh()->email_verified_at);
        Event::assertDispatched(Verified::class);
    }

    public function test_invalid_hash_does_not_verify(): void
    {
        $user = User::create([
            'name' => 'A', 'email' => 'a@example.com',
            'display_name' => 'a_taster', 'password' => bcrypt('x'),
        ]);

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id, 'hash' => 'wronghash',
        ]);

        $this->get($url);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_endpoint_dispatches_email(): void
    {
        Notification::fake();
        $user = User::create([
            'name' => 'A', 'email' => 'a@example.com',
            'display_name' => 'a_taster', 'password' => bcrypt('x'),
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/email/verify/resend')->assertOk();

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_register_still_succeeds_when_the_mailer_is_down(): void
    {
        // Prod outage 2026-06-10: mail was never configured on Fly, so the
        // synchronous verification send threw and every registration 500'd —
        // AFTER creating the user row, leaving the email "taken" with no
        // token ever issued. The send is best-effort: a dead mailer must not
        // take registration down with it.
        Notification::shouldReceive('send')
            ->andThrow(new \RuntimeException('mailer down'));

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Bob', 'email' => 'bob@example.com',
            'password' => 'secret-pw-123', 'password_confirmation' => 'secret-pw-123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'email']])
            // ops learns via report($e); the client learns via this flag.
            ->assertJsonPath('verification_email_sent', false);
        $this->assertNotNull(User::where('email', 'bob@example.com')->first());
    }

    public function test_resend_reports_an_honest_error_when_the_mailer_is_down(): void
    {
        // Unlike register, this endpoint's entire job is the send — so a
        // mailer failure surfaces as a 503 with a message, not a bare 500
        // and not a fake success.
        Notification::shouldReceive('send')
            ->andThrow(new \RuntimeException('mailer down'));

        $user = User::create([
            'name' => 'A', 'email' => 'a@example.com',
            'display_name' => 'a_taster', 'password' => bcrypt('x'),
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/email/verify/resend')
            ->assertStatus(503)
            ->assertJsonPath('ok', false);
    }

    public function test_forgot_password_still_returns_200_when_the_mailer_is_down(): void
    {
        // Same outage class as register — but here a 500 would also be an
        // account-enumeration oracle, since the send only happens for
        // registered emails. The endpoint's contract is "identical 200
        // either way"; a dead mailer must not break it.
        Notification::shouldReceive('send')
            ->andThrow(new \RuntimeException('mailer down'));

        User::create([
            'name' => 'A', 'email' => 'a@example.com',
            'display_name' => 'a_taster', 'password' => bcrypt('x'),
        ]);

        $this->postJson('/api/auth/forgot-password', ['email' => 'a@example.com'])
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_resend_for_already_verified_user_short_circuits(): void
    {
        Notification::fake();
        $user = User::create([
            'name' => 'A', 'email' => 'a@example.com',
            'display_name' => 'a_taster', 'password' => bcrypt('x'),
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/email/verify/resend')
            ->assertOk()
            ->assertJsonPath('already_verified', true);

        Notification::assertNothingSent();
    }
}
