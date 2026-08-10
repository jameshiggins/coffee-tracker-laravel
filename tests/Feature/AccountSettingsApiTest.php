<?php

namespace Tests\Feature;

use App\Models\Roaster;
use App\Models\RoasterFavorite;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Account settings: profile (name/display_name), email change with
 * re-verification, password change with other-session revocation, and
 * account deletion. Every account has a password hash (Google sign-ups get
 * a random one), so sensitive mutations uniformly require current_password;
 * `google_linked` in the payload lets the SPA point Google users to the
 * forgot-password flow to establish a known password.
 */
class AccountSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $overrides = []): User
    {
        $suffix = substr(bin2hex(random_bytes(3)), 0, 6);
        return User::create(array_merge([
            'name' => 'Alice Example',
            'email' => "alice-{$suffix}@example.com",
            'display_name' => 'alice_' . $suffix,
            'password' => Hash::make('correct-horse-8'),
            'email_verified_at' => now(),
        ], $overrides));
    }

    /** Mirrors GoogleAuthController: random password the user never saw. */
    private function makeGoogleUser(): User
    {
        $suffix = substr(bin2hex(random_bytes(3)), 0, 6);
        return $this->makeUser([
            'name' => 'Google Greta',
            'email' => "greta-{$suffix}@example.com",
            'display_name' => 'greta_' . $suffix,
            'google_id' => 'g-' . $suffix,
            'password' => bcrypt(Str::random(40)),
        ]);
    }

    /* ---------------- GET /me payload shape ---------------- */

    public function test_me_returns_the_auth_payload_shape(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.name', 'Alice Example')
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.display_name', $user->display_name)
            ->assertJsonPath('user.avatar_url', null)
            ->assertJsonPath('user.email_verified', true)
            ->assertJsonPath('user.google_linked', false);
    }

    public function test_me_reports_google_linked_for_oauth_accounts(): void
    {
        Sanctum::actingAs($this->makeGoogleUser());
        $this->getJson('/api/me')->assertJsonPath('user.google_linked', true);
    }

    /* ---------------- PATCH /me (profile) ---------------- */

    public function test_updates_name_and_display_name(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->patchJson('/api/me', ['name' => 'Alice B.', 'display_name' => 'alice-b'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Alice B.')
            ->assertJsonPath('user.display_name', 'alice-b');

        $user->refresh();
        $this->assertSame('Alice B.', $user->name);
        $this->assertSame('alice-b', $user->display_name);
    }

    public function test_resubmitting_own_display_name_passes(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->patchJson('/api/me', ['display_name' => $user->display_name])->assertOk();
    }

    public function test_taken_display_name_is_rejected(): void
    {
        $other = $this->makeUser();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->patchJson('/api/me', ['display_name' => $other->display_name])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('display_name');
    }

    public function test_display_name_regex_and_null_are_rejected(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->patchJson('/api/me', ['display_name' => 'has spaces!'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('display_name');

        // The public profile URL depends on it — clearing is not allowed.
        $this->patchJson('/api/me', ['display_name' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('display_name');
    }

    public function test_profile_update_requires_auth(): void
    {
        $this->patchJson('/api/me', ['name' => 'X'])->assertUnauthorized();
    }

    /* ---------------- PATCH /me/email ---------------- */

    public function test_email_change_requires_correct_current_password(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->patchJson('/api/me/email', [
            'email' => 'new@example.com',
            'current_password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertNotSame('new@example.com', $user->fresh()->email);
    }

    public function test_email_change_updates_unverifies_and_sends_verification(): void
    {
        Notification::fake();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->patchJson('/api/me/email', [
            'email' => 'new@example.com',
            'current_password' => 'correct-horse-8',
        ])->assertOk()
          ->assertJsonPath('user.email', 'new@example.com')
          ->assertJsonPath('user.email_verified', false);

        $user->refresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_email_change_rejects_duplicate_email(): void
    {
        $other = $this->makeUser();
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->patchJson('/api/me/email', [
            'email' => $other->email,
            'current_password' => 'correct-horse-8',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    /* ---------------- PATCH /me/password ---------------- */

    public function test_password_change_requires_correct_current_password(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->patchJson('/api/me/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password-9',
            'password_confirmation' => 'new-password-9',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_password_change_revokes_other_tokens_but_keeps_current(): void
    {
        $user = $this->makeUser();
        $current = $user->createToken('web')->plainTextToken;
        $other = $user->createToken('other-device')->plainTextToken;

        $this->withToken($current)->patchJson('/api/me/password', [
            'current_password' => 'correct-horse-8',
            'password' => 'new-password-9',
            'password_confirmation' => 'new-password-9',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-password-9', $user->fresh()->password));

        // Only the current session's token survives in the DB.
        $tokens = $user->tokens()->pluck('name');
        $this->assertCount(1, $tokens);
        $this->assertSame('web', $tokens[0]);

        // And it still authenticates. (Guard state is cached per test run, so
        // reset before switching tokens.)
        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson('/api/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_password_must_be_confirmed_and_min_length(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->patchJson('/api/me/password', [
            'current_password' => 'correct-horse-8',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->patchJson('/api/me/password', [
            'current_password' => 'correct-horse-8',
            'password' => 'new-password-9',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_google_user_can_reset_then_change_password(): void
    {
        // A Google user doesn't know their random password — the documented
        // path is forgot-password (already covered by PasswordResetTest).
        // Here: once they know one (e.g. post-reset), the change flow works.
        $user = $this->makeGoogleUser();
        $user->password = Hash::make('known-now-9');
        $user->save();
        Sanctum::actingAs($user);

        $this->patchJson('/api/me/password', [
            'current_password' => 'known-now-9',
            'password' => 'brand-new-10',
            'password_confirmation' => 'brand-new-10',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-10', $user->fresh()->password));
    }

    /* ---------------- DELETE /me ---------------- */

    public function test_delete_requires_correct_password(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->deleteJson('/api/me', ['current_password' => 'wrong-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertNotNull(User::find($user->id));
    }

    public function test_delete_removes_user_and_all_owned_rows(): void
    {
        $user = $this->makeUser();
        $roaster = Roaster::create(['name' => 'R', 'slug' => 'r', 'city' => 'V']);
        $coffee = $roaster->coffees()->create(['name' => 'Yirg', 'origin' => 'Ethiopia']);
        Wishlist::create(['user_id' => $user->id, 'coffee_id' => $coffee->id]);
        RoasterFavorite::create(['user_id' => $user->id, 'roaster_id' => $roaster->id]);
        $user->tastings()->create(['coffee_id' => $coffee->id, 'rating' => 4, 'tasted_on' => now()->toDateString()]);
        $token = $user->createToken('web')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/me', ['current_password' => 'correct-horse-8'])
            ->assertNoContent();

        $this->assertNull(User::find($user->id));
        $this->assertDatabaseMissing('wishlists', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('roaster_favorites', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('tastings', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class, 'tokenable_id' => $user->id,
        ]);
    }

    /* ---------------- register/login payloads stay in sync ---------------- */

    public function test_register_and_login_payloads_include_the_new_fields(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'New User',
            'email' => 'new-user@example.com',
            'password' => 'new-password-9',
            'password_confirmation' => 'new-password-9',
        ])->assertCreated()
          ->assertJsonPath('user.name', 'New User')
          ->assertJsonPath('user.google_linked', false);

        $this->postJson('/api/auth/login', [
            'email' => 'new-user@example.com',
            'password' => 'new-password-9',
        ])->assertOk()->assertJsonPath('user.google_linked', false);
    }
}
