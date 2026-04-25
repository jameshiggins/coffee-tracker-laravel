<?php

namespace App\Http\Controllers;

use App\Models\CoffeeVariant;
use App\Models\Roaster;
use Illuminate\Http\Request;

class RoasterController extends Controller
{
    public function index(Request $request)
    {
        $query = CoffeeVariant::with('coffee.roaster')
            ->whereHas('coffee.roaster', fn ($q) => $q->where('is_active', true));

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('coffee', fn ($c) => $c->where('name', 'like', "%{$search}%")
                    ->orWhere('origin', 'like', "%{$search}%"))
                  ->orWhereHas('coffee.roaster', fn ($r) => $r->where('name', 'like', "%{$search}%")
                      ->orWhere('region', 'like', "%{$search}%"));
            });
        }

        if ($region = $request->get('region')) {
            $query->whereHas('coffee.roaster', fn ($q) => $q->where('region', $region));
        }

        $variants = $query->get();

        $sort = $request->get('sort', 'roaster');
        $dir = $request->get('dir', 'asc');
        $allowedSorts = ['roaster', 'region', 'name', 'bag_weight_grams', 'price', 'cents_per_gram'];
        if (!in_array($sort, $allowedSorts)) $sort = 'roaster';
        if (!in_array($dir, ['asc', 'desc'])) $dir = 'asc';

        $variants = $variants->sortBy(function ($v) use ($sort) {
            return match ($sort) {
                'roaster' => $v->coffee->roaster->name,
                'region' => $v->coffee->roaster->region ?? '',
                'name' => $v->coffee->name,
                'bag_weight_grams' => $v->bag_weight_grams,
                'price' => (float) $v->price,
                'cents_per_gram' => $v->cents_per_gram,
                default => $v->coffee->roaster->name,
            };
        }, SORT_REGULAR, $dir === 'desc')->values();

        $totalRoasters = Roaster::where('is_active', true)->count();
        $totalCoffees = $variants->count();
        $avgPrice = $totalCoffees > 0 ? $variants->avg(fn ($v) => (float) $v->price) : 0;
        $avgCentsPerGram = $totalCoffees > 0 ? $variants->avg(fn ($v) => $v->cents_per_gram) : 0;

        $regions = Roaster::where('is_active', true)
            ->distinct()->whereNotNull('region')->orderBy('region')->pluck('region');

        return view('roasters.index', [
            'coffees' => $variants,
            'totalRoasters' => $totalRoasters,
            'totalCoffees' => $totalCoffees,
            'avgPrice' => $avgPrice,
            'avgCentsPerGram' => $avgCentsPerGram,
            'regions' => $regions,
            'sort' => $sort,
            'dir' => $dir,
        ]);
    }

    public function show(Roaster $roaster)
    {
        $coffees = $roaster->coffees()->with('variants')->orderBy('name')->get();
        return view('roasters.show', compact('roaster', 'coffees'));
    }
}
