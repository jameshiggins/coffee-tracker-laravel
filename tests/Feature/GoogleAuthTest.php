<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGoogleUser(array $overrides = []): SocialiteUser
    {
        $user = Mockery::mock(SocialiteUser::class);
        $defaults = [
            'getId' => 'google-uid-12345',
            'getEmail' => 'newuser@example.com',
            'getName' => 'New User',
            'getNickname' => 'newuser',
            'getAvatar' => 'https://lh3.googleusercontent.com/a/avatar.jpg',
        ];
        foreach (array_merge($defaults, $overrides) as $method => $value) {
            $user->shouldReceive($method)->andReturn($value);
        }
        return $user;
    }

    public function test_redirect_endpoint_sends_user_to_google(): void
    {
        config(['services.google.client_id' => 'fake-client-id']);
        config(['services.google.client_secret' => 'fake-secret']);

        $response = $this->get('/auth/google/redirect');

        $response->assertStatus(302);
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location'));
    }

    public function test_callback_creates_a_new_user_when_google_id_is_unknown(): void
    {
        Socialite::shouldReceive('driver->user')->andReturn($this->fakeGoogleUser());

        $this->assertDatabaseCount('users', 0);

        $response = $this->get('/auth/google/callback');

        $this->assertDatabaseCount('users', 1);
        $user = User::first();
        $this->assertSame('google-uid-12345', $user->google_id);
        $this->assertSame('newuser@example.com', $user->email);
        $this->assertSame('newuser', $user->display_name);
        $this->assertNotEmpty($user->avatar_url);

        // Should redirect to the frontend with a token in the query string.
        $response->assertStatus(302);
        $location = $response->headers->get('Location');
        $this->assertStringContainsString('token=', $location);
    }

    public function test_callback_links_to_existing_user_by_google_id(): void
    {
        $existing = User::create([
            'name' => 'Old Name',
            'email' => 'newuser@example.com',
            'display_name' => 'old_handle',
            'google_id' => 'google-uid-12345',
            'password' => bcrypt('whatever'),
        ]);

        Socialite::shouldReceive('driver->user')->andReturn($this->fakeGoogleUser());

        $this->get('/auth/google/callback');

        $this->assertDatabaseCount('users', 1);
        $this->assertSame($existing->id, User::first()->id);
    }

    public function test_callback_links_existing_email_user_to_their_google_id(): void
    {
        // Edge case: user previously signed up via email, now logs in with Google.
        $existing = User::create([
            'name' => 'Existing Name',
            'email' => 'newuser@example.com',
            'display_name' => 'email_user',
            'password' => bcrypt('secret'),
        ]);

        Socialite::shouldReceive('driver->user')->andReturn($this->fakeGoogleUser());

        $this->get('/auth/google/callback');

        $existing->refresh();
        $this->assertSame('google-uid-12345', $existing->google_id);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_callback_issues_usable_sanctum_token(): void
    {
        Socialite::shouldReceive('driver->user')->andReturn($this->fakeGoogleUser());

        $response = $this->get('/auth/google/callback');
        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? null;
        $this->assertNotEmpty($token);

        // The token must authenticate API requests.
        $this->getJson('/api/me', ['Authorization' => 'Bearer ' . $token])
            ->assertOk()
            ->assertJsonPath('user.email', 'newuser@example.com');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
