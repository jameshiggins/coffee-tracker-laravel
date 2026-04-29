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
        ])->assertCreated();

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
