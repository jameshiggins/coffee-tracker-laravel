<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmailAuthTest extends TestCase
{
    use RefreshDatabase;

    // ── REGISTRATION ─────────────────────────────────────────────────────

    public function test_register_creates_a_new_user_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Alice',
            'display_name' => 'alice_taster',
            'email' => 'alice@example.com',
            'password' => 'secret-password-123',
            'password_confirmation' => 'secret-password-123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'alice@example.com')
            ->assertJsonPath('user.display_name', 'alice_taster')
            ->assertJsonStructure(['token', 'user' => ['id', 'email', 'display_name']]);

        $this->assertDatabaseCount('users', 1);
        $user = User::first();
        $this->assertTrue(Hash::check('secret-password-123', $user->password));
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => bcrypt('x')]);

        $this->postJson('/api/auth/register', [
            'name' => 'Other',
            'email' => 'alice@example.com',
            'password' => 'whatever123',
            'password_confirmation' => 'whatever123',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_register_requires_password_confirmation_to_match(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret-password-123',
            'password_confirmation' => 'WRONG',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    public function test_register_requires_minimum_password_length(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }

    // ── LOGIN ────────────────────────────────────────────────────────────

    public function test_login_with_correct_credentials_returns_a_token(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => bcrypt('correct-password')]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'alice@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'email']]);

        $token = $response->json('token');
        $this->assertNotEmpty($token);

        // Token must work against authenticated endpoints.
        $this->getJson('/api/me', ['Authorization' => 'Bearer ' . $token])->assertOk();
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => bcrypt('correct-password')]);

        $this->postJson('/api/auth/login', [
            'email' => 'alice@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_login_with_unknown_email_is_rejected(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_login_does_not_leak_user_existence_via_different_error_messages(): void
    {
        // Both unknown-email and wrong-password should produce the same error key/message,
        // so an attacker can't enumerate valid emails.
        User::create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => bcrypt('correct')]);

        $unknown = $this->postJson('/api/auth/login', ['email' => 'no@example.com', 'password' => 'wrong']);
        $wrong = $this->postJson('/api/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong']);

        $this->assertSame(
            $unknown->json('errors.email'),
            $wrong->json('errors.email'),
            'Unknown-email and wrong-password must produce identical error messages'
        );
    }

    // ── LOGOUT ───────────────────────────────────────────────────────────

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => bcrypt('pw12345678')]);
        $token = $user->createToken('test')->plainTextToken;

        $this->assertSame(1, \Laravel\Sanctum\PersonalAccessToken::count());

        $this->postJson('/api/auth/logout', [], ['Authorization' => 'Bearer ' . $token])->assertNoContent();

        // The DB row should be gone after logout.
        $this->assertSame(0, \Laravel\Sanctum\PersonalAccessToken::count(), 'logout must delete the access token row');

        // Auth manager caches the resolved user across test requests; flush so the next
        // request actually re-queries with the (now-deleted) token. In production each
        // HTTP request boots a fresh app, so this isn't an issue outside tests.
        auth()->forgetGuards();

        // Same token must no longer work.
        $this->getJson('/api/me', ['Authorization' => 'Bearer ' . $token])->assertUnauthorized();
    }

    public function test_logout_unauthenticated_is_rejected(): void
    {
        $this->postJson('/api/auth/logout')->assertUnauthorized();
    }
}
