<?php

use App\Http\Controllers\RoasterController;
use App\Http\Controllers\Admin\RoasterController as AdminRoasterController;
use App\Http\Controllers\Admin\CoffeeController as AdminCoffeeController;
use App\Http\Controllers\Admin\VariantController as AdminVariantController;
use App\Http\Controllers\Admin\ModerationController as AdminModerationController;
use App\Http\Controllers\Auth\GoogleAuthController;
use Illuminate\Support\Facades\Route;

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

// Admin routes — HTTP Basic gated (BasicAdminAuth). The whole group is
// behind a single credential; the React app's Sanctum auth is separate.
Route::prefix('admin')->name('admin.')->middleware('admin.basic')->group(function () {
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

    Route::post('coffees/{coffee}/variants', [AdminVariantController::class, 'store'])
        ->name('variants.store');
    Route::put('variants/{variant}', [AdminVariantController::class, 'update'])
        ->name('variants.update');
    Route::delete('variants/{variant}', [AdminVariantController::class, 'destroy'])
        ->name('variants.destroy');

    Route::get('import', function () {
        return view('admin.roasters.import');
    })->name('roasters.import.form');

    Route::post('import', function (\Illuminate\Http\Request $request) {
        $data = $request->validate([
            'url' => 'required|url',
            'name' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'region' => 'nullable|string|max:255',
        ]);
        try {
            $roaster = (new \App\Services\RoasterImporter())->import(
                $data['url'], $data['name'] ?? null, $data['city'] ?? null, $data['region'] ?? null
            );
            return redirect()->route('admin.roasters.index')
                ->with('success', "Imported {$roaster->name} ({$roaster->coffees()->count()} coffees).");
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['url' => 'Import failed: ' . $e->getMessage()]);
        }
    })->name('roasters.import');

    Route::post('roasters/{roaster}/refresh', function (\App\Models\Roaster $roaster) {
        if (!$roaster->website) {
            return back()->withErrors(['url' => 'Roaster has no website to import from.']);
        }
        try {
            (new \App\Services\RoasterImporter())->import(
                $roaster->website, name: $roaster->name, city: $roaster->city, region: $roaster->region
            );
            return back()->with('success', "Refreshed {$roaster->name}.");
        } catch (\Throwable $e) {
            // Importer already persisted last_import_status='error'; flash for visibility.
            return back()->with('success', "Tried to refresh {$roaster->name}: " . $e->getMessage());
        }
    })->name('roasters.refresh');

    // Q17: moderation queue
    Route::get('moderation', [AdminModerationController::class, 'index'])->name('moderation.index');
    Route::post('moderation/{tasting}/hide', [AdminModerationController::class, 'hide'])->name('moderation.hide');
    Route::post('moderation/{id}/restore', [AdminModerationController::class, 'restore'])->name('moderation.restore');
    Route::post('moderation/{tasting}/dismiss', [AdminModerationController::class, 'dismiss'])->name('moderation.dismiss');

    Route::post('roasters/{roaster}/geocode', function (\App\Models\Roaster $roaster) {
        if (!$roaster->street_address) {
            return back()->withErrors(['street_address' => 'No street address to geocode. Edit the roaster first.']);
        }
        $hit = (new \App\Services\NominatimGeocoder())->geocode(
            $roaster->street_address, $roaster->city, $roaster->region, 'Canada'
        );
        if (!$hit) {
            return back()->with('success', "Geocode failed for {$roaster->name}: no match.");
        }
        $roaster->update(['latitude' => $hit['lat'], 'longitude' => $hit['lng']]);
        return back()->with('success', "Geocoded {$roaster->name} → {$hit['display_name']}");
    })->name('roasters.geocode');
});
