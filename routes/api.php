<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CoffeeApiController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\FavoriteRoasterController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PublicProfileController;
use App\Http\Controllers\Api\RoasterApiController;
use App\Http\Controllers\Api\TastingController;
use App\Http\Controllers\Api\WishlistController;
use Illuminate\Support\Facades\Route;

// Public auth — brute-force throttled (login keyed on email+IP, register per-IP).
Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

// Q15: password reset (always returns 200 to prevent account enumeration).
Route::post('/auth/forgot-password', [PasswordResetController::class, 'sendLink'])
    ->middleware('throttle:6,1');
Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:6,1');

// Public
Route::get('/roasters', [RoasterApiController::class, 'index']);
// Slim landing-page payload: roaster scalars + aggregates, no coffee tree.
// MUST be declared before /roasters/{roaster} or the slug binding eats it.
Route::get('/roasters/summary', [RoasterApiController::class, 'summary']);
// Trust#1: directory coverage + freshness summary.
Route::get('/stats', [RoasterApiController::class, 'stats']);
Route::get('/roasters/{roaster}', [RoasterApiController::class, 'show']);
// Bean-centric directory: paginated, filterable (origin/process/roast/in-stock/
// price), sortable. The server-side support the product's filters need.
Route::get('/coffees', [CoffeeApiController::class, 'index']);
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

    // Account: one payload shape everywhere (User::toAuthPayload — shared
    // with register/login so the SPA's auth state can never drift).
    Route::get('/me', [ProfileController::class, 'show']);
    Route::patch('/me', [ProfileController::class, 'update']);
    Route::patch('/me/email', [ProfileController::class, 'updateEmail'])->middleware('throttle:6,1');
    Route::patch('/me/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:6,1');
    Route::delete('/me', [ProfileController::class, 'destroy'])->middleware('throttle:6,1');
    Route::get('/tastings', [TastingController::class, 'index']);
    Route::post('/tastings', [TastingController::class, 'store']);
    Route::put('/tastings/{tasting}', [TastingController::class, 'update']);
    Route::delete('/tastings/{tasting}', [TastingController::class, 'destroy']);

    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist', [WishlistController::class, 'store']);
    Route::delete('/wishlist/{coffee}', [WishlistController::class, 'destroy']);

    // Pinned/favorite roasters — the roaster-level sibling of the wishlist.
    // {roaster} binds by slug (Roaster::getRouteKeyName), same as /roasters/{roaster}.
    Route::get('/me/favorite-roasters', [FavoriteRoasterController::class, 'index']);
    Route::post('/me/favorite-roasters', [FavoriteRoasterController::class, 'store']);
    Route::delete('/me/favorite-roasters/{roaster}', [FavoriteRoasterController::class, 'destroy']);

    // Q15: re-send verification email (rate-limited via throttle middleware).
    Route::post('/email/verify/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1');
});
