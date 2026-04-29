<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_dispatches_reset_link_for_known_email(): void
    {
        Notification::fake();
        $user = User::create([
            'name' => 'A', 'email' => 'a@example.com',
            'display_name' => 'a_taster', 'password' => bcrypt('old-pw-12345'),
        ]);

        $this->postJson('/api/auth/forgot-password', ['email' => 'a@example.com'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_forgot_password_returns_ok_for_unknown_email_to_prevent_enumeration(): void
    {
        Notification::fake();
        $r = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com']);
        $r->assertOk()->assertJsonPath('ok', true);
        Notification::assertNothingSent();
    }

    public function test_reset_password_updates_password_with_valid_token(): void
    {
        $user = User::create([
            'name' => 'A', 'email' => 'a@example.com',
            'display_name' => 'a_taster', 'password' => bcrypt('old-pw-12345'),
        ]);
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'a@example.com',
            'password' => 'new-pw-12345',
            'password_confirmation' => 'new-pw-12345',
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertTrue(Hash::check('new-pw-12345', $user->fresh()->password));
    }

    public function test_reset_password_rejects_bad_token(): void
    {
        $user = User::create([
            'name' => 'A', 'email' => 'a@example.com',
            'display_name' => 'a_taster', 'password' => bcrypt('old-pw-12345'),
        ]);

        $this->postJson('/api/auth/reset-password', [
            'token' => 'totally-bogus',
            'email' => 'a@example.com',
            'password' => 'new-pw-12345',
            'password_confirmation' => 'new-pw-12345',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('old-pw-12345', $user->fresh()->password));
    }
}
