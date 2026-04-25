<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Roaster;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoasterController extends Controller
{
    public function index()
    {
        $roasters = Roaster::with('coffees')->withCount('coffees')->orderBy('name')->paginate(50);
        return view('admin.roasters.index', compact('roasters'));
    }

    public function create()
    {
        return view('admin.roasters.form', ['roaster' => new Roaster()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'region'             => 'required|string|max:255',
            'city'               => 'required|string|max:255',
            'website'            => 'nullable|url|max:255',
            'instagram'          => 'nullable|string|max:255',
            'description'        => 'nullable|string',
            'has_shipping'       => 'boolean',
            'shipping_cost'      => 'nullable|numeric|min:0',
            'free_shipping_over' => 'nullable|numeric|min:0',
            'shipping_notes'     => 'nullable|string',
            'has_subscription'   => 'boolean',
            'subscription_notes' => 'nullable|string',
            'is_active'          => 'boolean',
        ]);

        $data['slug'] = Str::slug($data['name']);
        $data['has_shipping'] = $request->boolean('has_shipping');
        $data['has_subscription'] = $request->boolean('has_subscription');
        $data['is_active'] = $request->boolean('is_active', true);

        Roaster::create($data);

        return redirect()->route('admin.roasters.index')->with('success', 'Roaster added.');
    }

    public function edit(Roaster $roaster)
    {
        return view('admin.roasters.form', compact('roaster'));
    }

    public function update(Request $request, Roaster $roaster)
    {
        $data = $request->validate([
            'name'               => 'required|string|max:255',
            'region'             => 'required|string|max:255',
            'city'               => 'required|string|max:255',
            'website'            => 'nullable|url|max:255',
            'instagram'          => 'nullable|string|max:255',
            'description'        => 'nullable|string',
            'has_shipping'       => 'boolean',
            'shipping_cost'      => 'nullable|numeric|min:0',
            'free_shipping_over' => 'nullable|numeric|min:0',
            'shipping_notes'     => 'nullable|string',
            'has_subscription'   => 'boolean',
            'subscription_notes' => 'nullable|string',
            'is_active'          => 'boolean',
        ]);

        $data['slug'] = Str::slug($data['name']);
        $data['has_shipping'] = $request->boolean('has_shipping');
        $data['has_subscription'] = $request->boolean('has_subscription');
        $data['is_active'] = $request->boolean('is_active');

        $roaster->update($data);

        return redirect()->route('admin.roasters.index')->with('success', 'Roaster updated.');
    }

    public function destroy(Roaster $roaster)
    {
        $roaster->delete();
        return redirect()->route('admin.roasters.index')->with('success', 'Roaster deleted.');
    }
}
