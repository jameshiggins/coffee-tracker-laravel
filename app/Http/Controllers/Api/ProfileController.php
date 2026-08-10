<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Account settings for the signed-in user: profile fields, email change
 * (re-verification), password change, and account deletion.
 *
 * Every account has a password hash — Google sign-ups get a random one at
 * creation (GoogleAuthController) — so all sensitive mutations uniformly
 * require current_password. Google users who never chose a password
 * establish one via the existing forgot-password flow first; the SPA
 * surfaces that hint off the payload's `google_linked` flag.
 */
class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()->toAuthPayload()]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            // Same handle rules as registration; ignore-self so resubmitting
            // your own display_name isn't a false "taken". `required` (not
            // nullable): the /u/<handle> public-profile URL depends on it,
            // so clearing is not allowed.
            'display_name' => ['sometimes', 'required', 'string', 'min:2', 'max:50',
                               'regex:/^[A-Za-z0-9_-]+$/',
                               Rule::unique('users', 'display_name')->ignore($user->id)],
        ]);

        $user->fill($data)->save();

        return response()->json(['user' => $user->toAuthPayload()]);
    }

    public function updateEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => 'required|string',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $this->assertCurrentPassword($user->password, $data['current_password']);

        $user->email = $data['email'];
        $user->email_verified_at = null;
        $user->save();

        // Best-effort, same contract as registration (see AuthController):
        // a mailer outage must not turn a successful email change into a 500.
        $verificationEmailSent = true;
        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $e) {
            report($e);
            $verificationEmailSent = false;
        }

        AdminLog::info('account.email_changed', "Email changed for user #{$user->id}", [
            'user_id' => $user->id,
        ]);

        return response()->json([
            'user' => $user->toAuthPayload(),
            'verification_email_sent' => $verificationEmailSent,
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $this->assertCurrentPassword($user->password, $data['current_password']);

        $user->password = Hash::make($data['password']);
        $user->save();

        // Changing the password signs out every OTHER session — if it was
        // changed because a device was lost/compromised, those tokens must
        // die. The session doing the change stays alive. (currentAccessToken
        // is a TransientToken under Sanctum::actingAs — no DB id to spare.)
        $current = $user->currentAccessToken();
        $tokens = $user->tokens();
        if ($current instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $tokens->where('id', '!=', $current->id);
        }
        $tokens->delete();

        AdminLog::info('account.password_changed', "Password changed for user #{$user->id}", [
            'user_id' => $user->id,
        ]);

        return response()->json(['user' => $user->toAuthPayload()]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate(['current_password' => 'required|string']);
        $this->assertCurrentPassword($user->password, $data['current_password']);

        AdminLog::warning('account.deleted', "Account deleted: {$user->email}", [
            'user_id' => $user->id,
        ]);

        // Hard delete, privacy-first. Tastings, wishlists, and roaster
        // favorites all cascade via FK; tokens and reset codes don't, so
        // clear them explicitly inside the transaction.
        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            DB::table('password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
        });

        return response()->json(null, 204);
    }

    private function assertCurrentPassword(?string $hash, string $given): void
    {
        if ($hash === null || !Hash::check($given, $hash)) {
            throw ValidationException::withMessages([
                'current_password' => ['The password you entered is incorrect.'],
            ]);
        }
    }
}
