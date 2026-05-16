<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP Basic auth gate for the Blade operator console (/admin/*).
 *
 * The React app owns user auth (Sanctum tokens). The Blade admin has no
 * login flow of its own, so the minimal secure guard is HTTP Basic
 * against an env-configured credential — no login page to build, works
 * with every existing admin view unchanged.
 *
 * Fails CLOSED: if the credential isn't configured, nobody gets in
 * (better a locked-out operator than a world-writable admin). Uses
 * hash_equals for constant-time comparison so the check isn't a timing
 * oracle.
 */
class BasicAdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedUser = (string) config('admin.user');
        $expectedPass = (string) config('admin.pass');

        if ($expectedUser === '' || $expectedPass === '') {
            abort(503, 'Admin console authentication is not configured.');
        }

        $givenUser = (string) $request->getUser();
        $givenPass = (string) $request->getPassword();

        $userOk = hash_equals($expectedUser, $givenUser);
        $passOk = hash_equals($expectedPass, $givenPass);

        if ($userOk && $passOk) {
            return $next($request);
        }

        return response('Authentication required.', 401, [
            'WWW-Authenticate' => 'Basic realm="Roastmap Admin", charset="UTF-8"',
        ]);
    }
}
