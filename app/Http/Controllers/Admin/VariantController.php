<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coffee;
use App\Models\CoffeeVariant;
use Illuminate\Http\Request;

class VariantController extends Controller
{
    public function store(Request $request, Coffee $coffee)
    {
        $data = $request->validate([
            'bag_weight_grams' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'in_stock' => 'boolean',
            'purchase_link' => 'nullable|url|max:255',
        ]);
        $data['in_stock'] = $request->boolean('in_stock', true);
        $coffee->variants()->create($data);

        return redirect()->route('admin.coffees.edit', [$coffee->roaster, $coffee])
            ->with('success', 'Variant added.');
    }

    public function update(Request $request, CoffeeVariant $variant)
    {
        $data = $request->validate([
            'bag_weight_grams' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0',
            'in_stock' => 'boolean',
            'purchase_link' => 'nullable|url|max:255',
        ]);
        $data['in_stock'] = $request->boolean('in_stock');
        $variant->update($data);

        return redirect()
            ->route('admin.coffees.edit', [$variant->coffee->roaster, $variant->coffee])
            ->with('success', 'Variant updated.');
    }

    public function destroy(CoffeeVariant $variant)
    {
        $coffee = $variant->coffee;
        $variant->delete();
        return redirect()
            ->route('admin.coffees.edit', [$coffee->roaster, $coffee])
            ->with('success', 'Variant removed.');
    }
}
