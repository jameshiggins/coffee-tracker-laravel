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
        $coffee->delete();
        return redirect()->back()->with('success', 'Coffee offering deleted.');
    }
}
