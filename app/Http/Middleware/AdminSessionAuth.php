<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session gate for the Blade operator console (/admin/*).
 *
 * Replaces the original HTTP Basic gate (BasicAdminAuth) with a real login
 * page: the browser auth popup made for a poor operator experience and had
 * no logout or failure-throttling story. Credentials are still the single
 * env-configured pair (ADMIN_USER / ADMIN_PASS via config/admin.php) —
 * verification happens in Admin\LoginController with hash_equals and a
 * per-IP failure throttle; this middleware only checks the session flag.
 *
 * Fails CLOSED (unchanged contract): if the credential isn't configured,
 * nobody gets in — better a locked-out operator than a world-writable admin.
 */
class AdminSessionAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((string) config('admin.user') === '' || (string) config('admin.pass') === '') {
            abort(503, 'Admin console authentication is not configured.');
        }

        if ($request->session()->get('admin_authenticated') === true) {
            return $next($request);
        }

        // redirect()->guest remembers the intended URL, so logging in lands
        // the operator on the page they originally asked for.
        return redirect()->guest(route('admin.login'));
    }
}
