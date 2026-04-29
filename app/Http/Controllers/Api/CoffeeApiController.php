<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coffee;
use Illuminate\Http\JsonResponse;

/**
 * Coffee detail endpoint backing the /c/:id page (Q7). Returns the
 * full coffee record + its roaster + variants + aggregate rating
 * (Q8) so the React page can render the entire view with one fetch.
 */
class CoffeeApiController extends Controller
{
    public function show(Coffee $coffee): JsonResponse
    {
        $coffee->load(['roaster', 'variants', 'tastings' => fn ($q) => $q->where('is_public', true)]);
        return response()->json(['coffee' => $this->transform($coffee)]);
    }

    private function transform(Coffee $c): array
    {
        $publicTastings = $c->tastings; // already filtered to is_public=true
        $rated = $publicTastings->whereNotNull('rating');
        $avg = $rated->isEmpty() ? null : round($rated->avg('rating'), 1); // 1–10 internal

        $r = $c->roaster;
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
            'is_removed' => $c->removed_at !== null,
            'roaster' => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
                'city' => $r->city,
                'region' => $r->region,
                'country_code' => $r->country_code,
                'website' => $r->website,
            ],
            'variants' => $c->variants->map(fn ($v) => [
                'id' => $v->id,
                'bag_weight_grams' => $v->bag_weight_grams,
                'price' => (float) $v->price,
                'currency_code' => $v->currency_code ?? 'CAD',
                'in_stock' => (bool) $v->in_stock,
                'purchase_link' => $v->purchase_link,
                'price_per_gram' => $v->price_per_gram,
                'cents_per_gram' => $v->cents_per_gram,
            ])->values(),
            'rating' => [
                'count' => $rated->count(),
                'average' => $avg,                                  // 1–10 raw scale
                'average_stars' => $avg === null ? null : round($avg / 2, 1), // 1–5 star scale
            ],
        ];
    }
}
