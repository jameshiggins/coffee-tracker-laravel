<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coffee;
use App\Models\Roaster;
use Illuminate\Http\Request;

class CoffeeController extends Controller
{
    public function create(Roaster $roaster)
    {
        return view('admin.coffees.form', ['roaster' => $roaster, 'coffee' => new Coffee()]);
    }

    public function store(Request $request, Roaster $roaster)
    {
        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'origin'        => 'required|string|max:255',
            'process'       => 'nullable|string|max:100',
            'roast_level'   => 'nullable|string|max:100',
            'varietal'      => 'nullable|string|max:255',
            'tasting_notes' => 'nullable|string',
        ]);

        $coffee = $roaster->coffees()->create($data);

        return redirect()->route('admin.coffees.edit', [$roaster, $coffee])
            ->with('success', 'Coffee created. Add bag sizes below.');
    }

    public function edit(Roaster $roaster, Coffee $coffee)
    {
        $coffee->load('variants');
        return view('admin.coffees.form', compact('roaster', 'coffee'));
    }

    public function update(Request $request, Roaster $roaster, Coffee $coffee)
    {
        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'origin'        => 'required|string|max:255',
            'process'       => 'nullable|string|max:100',
            'roast_level'   => 'nullable|string|max:100',
            'varietal'      => 'nullable|string|max:255',
            'tasting_notes' => 'nullable|string',
        ]);

        $coffee->update($data);

        return redirect()->route('admin.coffees.edit', [$roaster, $coffee])
            ->with('success', 'Coffee updated.');
    }

    public function destroy(Roaster $roaster, Coffee $coffee)
    {
        // Soft-remove, never hard-delete. A real DELETE here would cascade
        // (coffees.roaster_id / tastings.coffee_id / wishlists.coffee_id are
        // cascadeOnDelete) and silently wipe every user tasting + wishlist
        // referencing this coffee, bypassing Tasting's SoftDeletes and audit
        // trail. Setting removed_at matches the importer's soft-remove contract:
        // the coffee drops out of every public surface (Coffee::scopeAvailable)
        // while preserving the rows user content FKs to.
        $coffee->update(['removed_at' => now()]);

        return redirect()->back()->with('success', 'Coffee offering removed from the directory (user tastings preserved).');
    }

    public function restore(Roaster $roaster, Coffee $coffee)
    {
        $coffee->update(['removed_at' => null]);

        return redirect()->back()->with('success', 'Coffee offering restored to the directory.');
    }
}
