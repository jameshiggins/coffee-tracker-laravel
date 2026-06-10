<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CoffeeResource;
use App\Models\Coffee;
use App\Models\Tasting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Coffee endpoints. show() backs the /c/:id detail page (Q7). index() is the
 * bean-centric directory listing: paginated, filterable (origin/process/roast/
 * in-stock/price), and sortable — the server-side support the product's
 * filters need, which the firehose /api/roasters never provided.
 */
class CoffeeApiController extends Controller
{
    /** Public, paginated, filterable bean directory. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'origin' => 'nullable|string|max:100',
            'process' => 'nullable|string|max:100',
            'roast' => 'nullable|string|max:100',
            'roaster' => 'nullable|string|max:255',   // roaster slug
            'is_blend' => 'nullable|boolean',
            'in_stock' => 'nullable|boolean',
            'min_cents_per_gram' => 'nullable|numeric|min:0',
            'max_cents_per_gram' => 'nullable|numeric|min:0',
            'sort' => 'nullable|in:price_asc,price_desc,name,newest',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = Coffee::query()
            ->available()
            ->whereHas('roaster', fn ($q) => $q->where('is_active', true))
            ->with(['roaster', 'variants']);

        if (! empty($filters['origin'])) {
            $query->where('origin', $filters['origin']);
        }
        if (! empty($filters['process'])) {
            $query->where('process', $filters['process']);
        }
        if (! empty($filters['roast'])) {
            $query->where('roast_level', $filters['roast']);
        }
        if (! empty($filters['roaster'])) {
            $query->whereHas('roaster', fn ($q) => $q->where('slug', $filters['roaster']));
        }
        if (array_key_exists('is_blend', $filters) && $filters['is_blend'] !== null) {
            $query->where('is_blend', (bool) $filters['is_blend']);
        }
        if (! empty($filters['in_stock'])) {
            $query->whereHas('variants', fn ($q) => $q->where('in_stock', true));
        }
        if (isset($filters['min_cents_per_gram'])) {
            $query->where('best_cents_per_gram', '>=', $filters['min_cents_per_gram']);
        }
        if (isset($filters['max_cents_per_gram'])) {
            $query->where('best_cents_per_gram', '<=', $filters['max_cents_per_gram']);
        }

        match ($filters['sort'] ?? 'name') {
            // NULL prices sort last on a cheap ascending sort.
            'price_asc' => $query->orderByRaw('best_cents_per_gram IS NULL, best_cents_per_gram ASC'),
            'price_desc' => $query->orderByDesc('best_cents_per_gram'),
            'newest' => $query->orderByDesc('id'),
            default => $query->orderBy('name'),
        };

        $page = $query->paginate($filters['per_page'] ?? 24)->withQueryString();

        // One grouped rating query for the whole page, attached per coffee so
        // CoffeeResource never triggers an N+1.
        $ratingMap = Tasting::ratingMapFor($page->getCollection()->pluck('id')->all());
        $page->getCollection()->each(function (Coffee $coffee) use ($ratingMap) {
            $coffee->setAttribute('rating_summary', $ratingMap[$coffee->id]
                ?? ['count' => 0, 'average' => null, 'average_stars' => null]);
        });

        return CoffeeResource::collection($page);
    }

    public function show(Coffee $coffee): JsonResponse
    {
        $coffee->load(['roaster', 'variants', 'tastings' => fn ($q) => $q->where('is_public', true)]);

        // Don't serve coffees whose roaster was deactivated (a deactivated
        // roaster is a moderation "hide" — its beans must disappear too).
        abort_if($coffee->roaster === null || ! $coffee->roaster->is_active, 404);

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
