<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Login page for the Blade operator console. Verifies the single
 * env-configured credential pair (config/admin.php → ADMIN_USER/ADMIN_PASS)
 * with hash_equals (no timing oracle) and throttles FAILURES per IP so the
 * form can't be brute-forced — successful operator traffic is never counted,
 * so the throttle can't lock out normal admin browsing.
 */
class LoginController extends Controller
{
    /** Failed attempts allowed per IP before the cooldown. */
    private const MAX_FAILURES = 10;

    /** Cooldown window in seconds once the failure cap is hit. */
    private const DECAY_SECONDS = 300;

    public function show(Request $request)
    {
        $this->assertConfigured();

        if ($request->session()->get('admin_authenticated') === true) {
            return redirect()->route('admin.roasters.index');
        }

        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $this->assertConfigured();

        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $key = 'admin-login:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            $seconds = RateLimiter::availableIn($key);

            return back()->withErrors([
                'auth' => "Too many failed attempts. Try again in {$seconds} seconds.",
            ])->onlyInput('username');
        }

        $userOk = hash_equals((string) config('admin.user'), (string) $request->input('username'));
        $passOk = hash_equals((string) config('admin.pass'), (string) $request->input('password'));

        if (! ($userOk && $passOk)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return back()->withErrors([
                'auth' => 'Those credentials don’t match.',
            ])->onlyInput('username');
        }

        RateLimiter::clear($key);

        // Session fixation defense: new session id on privilege change.
        $request->session()->regenerate();
        $request->session()->put('admin_authenticated', true);

        return redirect()->intended(route('admin.roasters.index'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('admin_authenticated');
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    private function assertConfigured(): void
    {
        if ((string) config('admin.user') === '' || (string) config('admin.pass') === '') {
            abort(503, 'Admin console authentication is not configured.');
        }
    }
}
