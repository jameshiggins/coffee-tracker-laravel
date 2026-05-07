<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CoffeeApiController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PublicProfileController;
use App\Http\Controllers\Api\RoasterApiController;
use App\Http\Controllers\Api\TastingController;
use App\Http\Controllers\Api\WishlistController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public auth
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Q15: password reset (always returns 200 to prevent account enumeration).
Route::post('/auth/forgot-password', [PasswordResetController::class, 'sendLink'])
    ->middleware('throttle:6,1');
Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:6,1');

// Public
Route::get('/roasters', [RoasterApiController::class, 'index']);
Route::get('/roasters/{roaster}', [RoasterApiController::class, 'show']);
Route::get('/coffees/{coffee}', [CoffeeApiController::class, 'show']);
Route::get('/coffees/{coffee}/tastings', [TastingController::class, 'publicForCoffee']);

// Q9: per-tasting permalinks + public profiles
Route::get('/tastings/{tasting}/public', [PublicProfileController::class, 'showTasting']);
Route::get('/users/{displayName}', [PublicProfileController::class, 'showByDisplayName']);

// Q17: anyone can flag a public tasting for moderator review.
// Throttled hard — this is a write endpoint exposed to the open internet.
Route::post('/tastings/{tasting}/report', [TastingController::class, 'report'])
    ->middleware('throttle:10,1');

// Q15: email verification — public link, signed by Laravel.
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->middleware('signed')
    ->name('verification.verify');

// Authenticated
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/me', function (Request $request) {
        $u = $request->user();
        return response()->json(['user' => [
            'id' => $u->id,
            'email' => $u->email,
            'display_name' => $u->display_name,
            'avatar_url' => $u->avatar_url,
        ]]);
    });
    Route::get('/tastings', [TastingController::class, 'index']);
    Route::post('/tastings', [TastingController::class, 'store']);
    Route::put('/tastings/{tasting}', [TastingController::class, 'update']);
    Route::delete('/tastings/{tasting}', [TastingController::class, 'destroy']);

    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist', [WishlistController::class, 'store']);
    Route::delete('/wishlist/{coffee}', [WishlistController::class, 'destroy']);

    // Q15: re-send verification email (rate-limited via throttle middleware).
    Route::post('/email/verify/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1');
});
