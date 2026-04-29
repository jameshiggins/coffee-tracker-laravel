<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'         => 'required|string|max:255',
            // Q9: display_name is the URL handle for /u/<display_name>.
            // Restricted to alphanumerics, hyphens, underscores so URLs stay
            // pretty without percent-encoding. Unique across users.
            'display_name' => ['nullable', 'string', 'min:2', 'max:50',
                               'regex:/^[A-Za-z0-9_-]+$/', 'unique:users,display_name'],
            'email'        => 'required|email|max:255|unique:users,email',
            'password'     => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name'         => $data['name'],
            'display_name' => $data['display_name'] ?? $this->fallbackDisplayName($data['name']),
            'email'        => $data['email'],
            'password'     => Hash::make($data['password']),
        ]);

        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $this->userPayload($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->input('email'))->first();
        // Identical error for "unknown email" and "wrong password" — prevents enumeration.
        if (!$user || !$user->password || !Hash::check($request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('web')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user'  => $this->userPayload($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(null, 204);
    }

    /**
     * Generate a URL-safe display_name from a real name when the user
     * doesn't pick one. Slugifies, then appends "-<random>" if the slug
     * is already taken.
     */
    private function fallbackDisplayName(string $name): string
    {
        $base = preg_replace('/[^A-Za-z0-9_-]/', '', strtolower(str_replace(' ', '-', $name)));
        if ($base === '') $base = 'taster';
        $candidate = $base;
        $tries = 0;
        while (User::where('display_name', $candidate)->exists()) {
            $candidate = $base . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
            if (++$tries > 5) break;
        }
        return $candidate;
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'display_name' => $user->display_name,
            'avatar_url' => $user->avatar_url,
        ];
    }
}
