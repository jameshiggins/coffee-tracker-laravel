<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * Q15a: send a password-reset link to the email.
     * Always returns 200 with the same response body whether or not the
     * email is registered — prevents account enumeration.
     */
    public function sendLink(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);
        // Best-effort send, for two reasons: (1) a dead mailer must not 500
        // the endpoint (2026-06-10 outage class — mail was never configured
        // in prod); (2) the send only ever happens for REGISTERED emails, so
        // letting it throw turns "500 vs 200" into an account-enumeration
        // oracle, defeating this endpoint's same-response-either-way contract.
        try {
            Password::sendResetLink($request->only('email'));
        } catch (\Throwable $e) {
            report($e);
        }
        return response()->json(['ok' => true]);
    }

    /**
     * Q15b: complete the reset with the emailed token.
     * Sanctum tokens stay valid (intentional — no force-logout-everywhere
     * for v1; revisit when there's evidence of credential stuffing).
     */
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => 'required',
            'email'    => 'required|email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();
                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json(['ok' => true]);
        }
        return response()->json([
            'ok' => false,
            'error' => __($status),
        ], 422);
    }
}
