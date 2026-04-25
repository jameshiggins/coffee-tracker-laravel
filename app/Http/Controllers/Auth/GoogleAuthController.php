<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
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
            return redirect($this->frontendUrl('?auth_error=1'));
        }

        $user = User::where('google_id', $googleUser->getId())->first()
             ?? User::where('email', $googleUser->getEmail())->first();

        if ($user) {
            $user->fill([
                'google_id' => $googleUser->getId(),
                'avatar_url' => $googleUser->getAvatar(),
                'display_name' => $user->display_name ?? $googleUser->getNickname() ?? $googleUser->getName(),
            ])->save();
        } else {
            $user = User::create([
                'name' => $googleUser->getName() ?? $googleUser->getEmail(),
                'email' => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'display_name' => $googleUser->getNickname() ?? $googleUser->getName(),
                'avatar_url' => $googleUser->getAvatar(),
                'password' => bcrypt(Str::random(40)),
            ]);
        }

        $token = $user->createToken('web')->plainTextToken;

        return redirect($this->frontendUrl('?token=' . urlencode($token)));
    }

    private function frontendUrl(string $suffix = ''): string
    {
        return rtrim(config('services.google.frontend_url'), '/') . '/auth/callback' . $suffix;
    }
}
