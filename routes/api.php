<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CoffeeApiController;
use App\Http\Controllers\Api\RoasterApiController;
use App\Http\Controllers\Api\TastingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public auth
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Public
Route::get('/roasters', [RoasterApiController::class, 'index']);
Route::get('/roasters/{roaster}', [RoasterApiController::class, 'show']);
Route::get('/coffees/{coffee}', [CoffeeApiController::class, 'show']);
Route::get('/coffees/{coffee}/tastings', [TastingController::class, 'publicForCoffee']);

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
});
