<?php

use App\Http\Controllers\Admin\RoasterController as AdminRoasterController;
use App\Http\Controllers\Admin\CoffeeController as AdminCoffeeController;
use App\Http\Controllers\Admin\VariantController as AdminVariantController;
use App\Http\Controllers\Admin\ModerationController as AdminModerationController;
use App\Http\Controllers\Admin\LoginController as AdminLoginController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Ops: liveness/readiness probe for uptime monitors. Public, no secrets in
// the body. 200 healthy / 503 degraded — see HealthController.
Route::get('/up', HealthController::class)->name('health');

Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');

// The user-facing app is React (Vite at :5174 in dev, the FRONTEND_URL
// in prod). Anyone landing on the Laravel root or the old /roasters/*
// Blade pages gets bounced to the React app — these legacy Blade views
// pre-date the React rewrite and shouldn't be reached anymore.
$frontendUrl = env('FRONTEND_URL', 'http://localhost:5174');
Route::get('/', fn () => redirect()->away($frontendUrl . '/'));
Route::get('/roasters/{slug}', fn (string $slug) => redirect()->away(env('FRONTEND_URL', 'http://localhost:5174') . '/beans?roaster=' . urlencode($slug)));

// Convenience redirect: /admin → /admin/roasters (the actual admin home).
Route::get('/admin', fn () => redirect()->route('admin.roasters.index'));

// Admin login/logout — OUTSIDE the gate (you have to be able to reach the
// form). Credential verification + failure throttling live in the
// controller; session flag is what AdminSessionAuth checks.
Route::get('/admin/login', [AdminLoginController::class, 'show'])->name('admin.login');
Route::post('/admin/login', [AdminLoginController::class, 'login'])->name('admin.login.attempt');
Route::post('/admin/logout', [AdminLoginController::class, 'logout'])->name('admin.logout');

// Admin routes — session-gated (AdminSessionAuth; see /admin/login above).
// The whole group is behind a single env credential; the React app's
// Sanctum auth is separate.
Route::prefix('admin')->name('admin.')->middleware('admin.auth')->group(function () {
    Route::resource('roasters', AdminRoasterController::class)->except(['show']);

    Route::get('roasters/{roaster}/coffees/create', [AdminCoffeeController::class, 'create'])
        ->name('coffees.create');
    Route::post('roasters/{roaster}/coffees', [AdminCoffeeController::class, 'store'])
        ->name('coffees.store');
    Route::get('roasters/{roaster}/coffees/{coffee}/edit', [AdminCoffeeController::class, 'edit'])
        ->name('coffees.edit');
    Route::put('roasters/{roaster}/coffees/{coffee}', [AdminCoffeeController::class, 'update'])
        ->name('coffees.update');
    Route::delete('roasters/{roaster}/coffees/{coffee}', [AdminCoffeeController::class, 'destroy'])
        ->name('coffees.destroy');
    Route::post('roasters/{roaster}/coffees/{coffee}/restore', [AdminCoffeeController::class, 'restore'])
        ->name('coffees.restore');

    Route::post('coffees/{coffee}/variants', [AdminVariantController::class, 'store'])
        ->name('variants.store');
    Route::put('variants/{variant}', [AdminVariantController::class, 'update'])
        ->name('variants.update');
    Route::delete('variants/{variant}', [AdminVariantController::class, 'destroy'])
        ->name('variants.destroy');

    // Import / refresh / geocode — logic lives in AdminRoasterController
    // (testable, consistent with the resourceful actions above) rather than
    // inline closures. import/refresh queue an ImportRoasterJob.
    Route::get('import', [AdminRoasterController::class, 'importForm'])->name('roasters.import.form');
    Route::post('import', [AdminRoasterController::class, 'import'])->name('roasters.import');
    Route::post('roasters/{roaster}/refresh', [AdminRoasterController::class, 'refresh'])->name('roasters.refresh');
    Route::post('roasters/{roaster}/geocode', [AdminRoasterController::class, 'geocode'])->name('roasters.geocode');

    // Q17: moderation queue
    Route::get('moderation', [AdminModerationController::class, 'index'])->name('moderation.index');
    Route::post('moderation/{tasting}/hide', [AdminModerationController::class, 'hide'])->name('moderation.hide');
    Route::post('moderation/{id}/restore', [AdminModerationController::class, 'restore'])->name('moderation.restore');
    Route::post('moderation/{tasting}/dismiss', [AdminModerationController::class, 'dismiss'])->name('moderation.dismiss');
});
