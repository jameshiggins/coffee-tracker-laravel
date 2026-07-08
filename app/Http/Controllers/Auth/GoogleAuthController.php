<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AdminLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            Log::warning('Google OAuth callback failed', ['error' => $e->getMessage()]);
            AdminLog::warning('auth.google.callback_failed', 'Google OAuth callback failed: ' . $e->getMessage());
            return redirect($this->frontendUrl('?auth_error=1'));
        }

        try {
            $user = $this->findOrCreateUser($googleUser);
        } catch (\Throwable $e) {
            // H3: a display_name collision (two Google users with the same
            // name) used to throw an uncaught QueryException → 500. Provision
            // through the unique-handle generator and degrade gracefully if
            // anything still fails, instead of 500-ing the OAuth callback.
            Log::warning('Google OAuth user provisioning failed', ['error' => $e->getMessage()]);
            AdminLog::warning('auth.google.provisioning_failed', 'Google OAuth provisioning failed: ' . $e->getMessage());
            return redirect($this->frontendUrl('?auth_error=1'));
        }

        $token = $user->createToken('web')->plainTextToken;
        AdminLog::info('auth.google.signed_in', "Google sign-in: {$user->email}", [
            'user_id' => $user->id, 'new_account' => $user->wasRecentlyCreated,
        ]);

        // NOTE: the token rides the query string because the out-of-repo React
        // SPA reads it from there; changing the channel is a cross-repo
        // contract change. Token-at-rest exposure is bounded by the SANCTUM
        // token lifetime (config/sanctum.php 'expiration').
        return redirect($this->frontendUrl('?token=' . urlencode($token)));
    }

    private function findOrCreateUser($googleUser): User
    {
        // Prefer the stable Google subject id.
        $user = User::where('google_id', $googleUser->getId())->first();

        // Fall back to linking by email — but never when Google EXPLICITLY
        // reports the address as unverified (account-takeover guard). An
        // absent flag is treated as verified to preserve the email→Google
        // linking flow.
        if (! $user && $googleUser->getEmail() && ! $this->googleEmailUnverified($googleUser)) {
            $user = User::where('email', $googleUser->getEmail())->first();
        }

        if ($user) {
            $user->fill([
                'google_id' => $googleUser->getId(),
                // Preserve an existing handle; only backfill a null one, and
                // route it through the unique-handle generator.
                'display_name' => $user->display_name
                    ?? User::generateDisplayName($googleUser->getNickname() ?? $googleUser->getName()),
                // Don't clobber an avatar the user already has.
                'avatar_url' => $user->avatar_url ?? $googleUser->getAvatar(),
            ])->save();

            return $user;
        }

        return User::create([
            'name' => $googleUser->getName() ?? $googleUser->getEmail(),
            'email' => $googleUser->getEmail(),
            'google_id' => $googleUser->getId(),
            'display_name' => User::generateDisplayName($googleUser->getNickname() ?? $googleUser->getName()),
            'avatar_url' => $googleUser->getAvatar(),
            'password' => bcrypt(Str::random(40)),
        ]);
    }

    /** True only when Google explicitly asserts the email is NOT verified. */
    private function googleEmailUnverified($googleUser): bool
    {
        $raw = isset($googleUser->user) && is_array($googleUser->user) ? $googleUser->user : [];
        if (array_key_exists('email_verified', $raw)) {
            return $raw['email_verified'] === false;
        }
        if (array_key_exists('verified_email', $raw)) {
            return $raw['verified_email'] === false;
        }

        return false;
    }

    private function frontendUrl(string $suffix = ''): string
    {
        return rtrim(config('services.google.frontend_url'), '/') . '/auth/callback' . $suffix;
    }
}
