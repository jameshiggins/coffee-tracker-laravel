<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ImportRoasterJob;
use App\Models\Roaster;
use App\Services\NominatimGeocoder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RoasterController extends Controller
{
    public function index()
    {
        // Sort by status so problem roasters (errors / empty / never imported)
        // bubble to the top of the admin index. Within each status bucket,
        // keep alphabetical.
        $statusOrder = "CASE last_import_status
            WHEN 'error' THEN 0
            WHEN 'empty' THEN 2
            WHEN 'success' THEN 4
            ELSE 3 END"; // null = 3, never imported
        $roasters = Roaster::with('coffees.variants')
            ->withCount('coffees')
            ->orderByRaw($statusOrder)
            ->orderBy('name')
            ->paginate(50);
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
            'street_address'     => 'nullable|string|max:255',
            'postal_code'        => 'nullable|string|max:16',
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
            'street_address'     => 'nullable|string|max:255',
            'postal_code'        => 'nullable|string|max:16',
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
        // Soft-remove, never hard-delete. coffees.roaster_id is cascadeOnDelete,
        // so a real DELETE would destroy every coffee for this roaster and, in
        // turn, every user tasting + wishlist that FKs to those coffees. We
        // deactivate instead: is_active=false drops the roaster (and its beans)
        // from every public surface while keeping all data recoverable.
        $roaster->update(['is_active' => false]);

        return redirect()->route('admin.roasters.index')
            ->with('success', 'Roaster deactivated and hidden from the directory (data preserved). Re-activate any time by editing it.');
    }

    public function importForm()
    {
        return view('admin.roasters.import');
    }

    public function import(Request $request)
    {
        $data = $request->validate([
            'url' => 'required|url',
            'name' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'region' => 'nullable|string|max:255',
        ]);

        // Off the web request: a full scrape can take minutes and would
        // otherwise time out the admin POST. The worker runs it; the importer
        // records last_import_status for visibility on the index.
        ImportRoasterJob::dispatch(
            $data['url'], $data['name'] ?? null, $data['city'] ?? null, $data['region'] ?? null
        );

        return redirect()->route('admin.roasters.index')
            ->with('success', "Import queued for {$data['url']} — it runs in the background; refresh in a moment to see the roaster and its beans.");
    }

    public function refresh(Roaster $roaster)
    {
        if (! $roaster->website) {
            return back()->withErrors(['url' => 'Roaster has no website to import from.']);
        }

        ImportRoasterJob::dispatch(
            $roaster->website, $roaster->name, $roaster->city, $roaster->region
        );

        return back()->with('success', "Refresh queued for {$roaster->name} — runs in the background.");
    }

    public function geocode(Roaster $roaster)
    {
        if (! $roaster->street_address) {
            return back()->withErrors(['street_address' => 'No street address to geocode. Edit the roaster first.']);
        }

        $hit = (new NominatimGeocoder())->geocode(
            $roaster->street_address, $roaster->city, $roaster->region, 'Canada'
        );
        if (! $hit) {
            return back()->with('success', "Geocode failed for {$roaster->name}: no match.");
        }

        // Stamp address_source='manual' so the monthly address sweep treats
        // this as resolved and never overwrites the hand-placed pin (matches
        // the 'manual' convention ApplyRoasterCorrections already uses).
        $roaster->update([
            'latitude' => $hit['lat'],
            'longitude' => $hit['lng'],
            'address_source' => 'manual',
            'address_verified_at' => now(),
        ]);

        return back()->with('success', "Geocoded {$roaster->name} → {$hit['display_name']}");
    }
}
