<?php

use App\Http\Controllers\RoasterController;
use App\Http\Controllers\Admin\RoasterController as AdminRoasterController;
use App\Http\Controllers\Admin\CoffeeController as AdminCoffeeController;
use App\Http\Controllers\Admin\VariantController as AdminVariantController;
use App\Http\Controllers\Auth\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');

// Public routes
Route::get('/', [RoasterController::class, 'index'])->name('roasters.index');
Route::get('/roasters/{roaster}', [RoasterController::class, 'show'])->name('roasters.show');

// Admin routes
Route::prefix('admin')->name('admin.')->group(function () {
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
});
