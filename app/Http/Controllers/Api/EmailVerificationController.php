<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class EmailVerificationController extends Controller
{
    /**
     * Endpoint the verification email links to. Validates Laravel's signed
     * URL parameters, marks the user verified, redirects to the frontend.
     *
     * Routed as /api/email/verify/{id}/{hash} — Laravel signs URLs with the
     * APP_KEY so we can trust the {id} parameter without further auth.
     */
    public function verify(Request $request, int $id, string $hash)
    {
        $user = User::findOrFail($id);
        if (!hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return $this->frontendRedirect('verified', ['error' => 'invalid']);
        }
        if ($user->hasVerifiedEmail()) {
            return $this->frontendRedirect('verified', ['already' => '1']);
        }
        $user->markEmailAsVerified();
        event(new Verified($user));
        return $this->frontendRedirect('verified', ['ok' => '1']);
    }

    /** Resend the verification email — rate-limited to once per minute. */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return response()->json(['ok' => true, 'already_verified' => true]);
        }
        $user->sendEmailVerificationNotification();
        return response()->json(['ok' => true]);
    }

    private function frontendRedirect(string $page, array $params)
    {
        $base = rtrim(Config::get('services.google.frontend_url', 'http://localhost:5174'), '/');
        $qs = http_build_query($params);
        return redirect("{$base}/{$page}?{$qs}");
    }
}
