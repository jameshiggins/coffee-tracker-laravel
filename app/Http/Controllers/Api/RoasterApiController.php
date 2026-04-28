<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Roaster;
use Illuminate\Http\JsonResponse;

class RoasterApiController extends Controller
{
    public function index(): JsonResponse
    {
        $roasters = Roaster::with(['coffees' => fn ($q) => $q->whereNull('removed_at'), 'coffees.variants'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json([
            'roasters' => $roasters->map(fn ($r) => $this->transformRoaster($r))->values(),
        ]);
    }

    public function show(Roaster $roaster): JsonResponse
    {
        $roaster->load([
            'coffees' => fn ($q) => $q->whereNull('removed_at'),
            'coffees.variants',
        ]);
        return response()->json($this->transformRoaster($roaster, true));
    }

    private function transformRoaster(Roaster $roaster, bool $detail = false): array
    {
        return [
            'id' => $roaster->id,
            'slug' => $roaster->slug,
            'name' => $roaster->name,
            'city' => $roaster->city,
            'region' => $roaster->region,
            'country_code' => $roaster->country_code,
            'street_address' => $roaster->street_address,
            'postal_code' => $roaster->postal_code,
            'latitude' => $roaster->latitude,
            'longitude' => $roaster->longitude,
            'ships_to' => $roaster->ships_to ?? [],
            'website' => $roaster->website,
            'instagram' => $roaster->instagram,
            'description' => $detail ? $roaster->description : null,
            'has_shipping' => (bool) $roaster->has_shipping,
            'shipping_cost' => $roaster->shipping_cost !== null ? (float) $roaster->shipping_cost : null,
            'free_shipping_over' => $roaster->free_shipping_over !== null ? (float) $roaster->free_shipping_over : null,
            'shipping_notes' => $roaster->shipping_notes,
            'has_subscription' => (bool) $roaster->has_subscription,
            'subscription_notes' => $roaster->subscription_notes,
            'coffees' => $roaster->coffees->map(function ($c) {
                $variants = $c->variants->map(fn ($v) => [
                    'id' => $v->id,
                    'bag_weight_grams' => $v->bag_weight_grams,
                    'price' => (float) $v->price,
                    'currency_code' => $v->currency_code ?? 'CAD',
                    'in_stock' => (bool) $v->in_stock,
                    'purchase_link' => $v->purchase_link,
                    'price_per_gram' => $v->price_per_gram,
                    'cents_per_gram' => $v->cents_per_gram,
                ])->values();
                // "Default" variant is just the smallest in-stock one — variants are
                // already ordered ascending by bag_weight_grams in the relation.
                $default = $variants->firstWhere('in_stock', true) ?? $variants->first();
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'origin' => $c->origin,
                    'process' => $c->process,
                    'roast_level' => $c->roast_level,
                    'varietal' => $c->varietal,
                    'tasting_notes' => $c->tasting_notes,
                    'description' => $c->description,
                    'product_url' => $c->product_url,
                    'image_url' => $c->image_url,
                    'is_blend' => (bool) $c->is_blend,
                    'best_price_per_gram' => $c->best_price_per_gram,
                    'default_variant' => $default,
                    'variants' => $variants,
                ];
            })->values(),
            'best_price_per_gram' => $roaster->coffees
                ->map(fn ($c) => $c->best_price_per_gram)
                ->filter()
                ->min(),
            'coffees_count' => $roaster->coffees->count(),
            'variants_count' => $roaster->coffees->sum(fn ($c) => $c->variants->count()),
        ];
    }
}
