<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coffee;
use App\Models\Roaster;
use App\Models\Tasting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class RoasterApiController extends Controller
{
    public function index(): JsonResponse
    {
        $roasters = Roaster::with(['coffees' => fn ($q) => $q->whereNull('removed_at'), 'coffees.variants'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        // Single grouped query for aggregate ratings across every coffee
        // shown in this response. Cheaper than N+1 sub-queries; result is
        // a [coffee_id => ['count' => N, 'avg' => X.X]] lookup map.
        $coffeeIds = $roasters->flatMap(fn ($r) => $r->coffees->pluck('id'))->all();
        $ratingMap = $this->buildRatingMap($coffeeIds);

        // JSON_INVALID_UTF8_SUBSTITUTE: render U+FFFD in place of invalid
        // UTF-8 bytes instead of throwing InvalidArgumentException (which
        // 500s the whole endpoint when any single coffee has bad bytes).
        // Import-time sanitize in RoasterImporter prevents new bad bytes;
        // this defends against the existing tail of historical rows.
        return response()->json([
            'roasters' => $roasters->map(fn ($r) => $this->transformRoaster($r, false, $ratingMap))->values(),
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function show(Roaster $roaster): JsonResponse
    {
        $roaster->load([
            'coffees' => fn ($q) => $q->whereNull('removed_at'),
            'coffees.variants',
        ]);
        $ratingMap = $this->buildRatingMap($roaster->coffees->pluck('id')->all());
        return response()->json($this->transformRoaster($roaster, true, $ratingMap), 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Trust#1: directory-wide coverage + freshness summary. Powers a public
     * "how complete and current is this data" panel — total roasters/coffees,
     * how many were refreshed recently vs. gone stale, and how much of the map
     * is actually placed. All read from columns already on the roaster row, so
     * this is a handful of cheap COUNT()s with no per-row work.
     */
    public function stats(): JsonResponse
    {
        $freshWithinDays = 7;
        $freshCutoff = Carbon::now()->subDays($freshWithinDays);

        $roastersTotal = Roaster::where('is_active', true)->count();

        // Freshness buckets over the active set, partitioned so they sum to the
        // total: fresh (a recent successful import), never (no import on record),
        // stale (everything else — old, empty, or errored).
        $fresh = Roaster::where('is_active', true)
            ->where('last_import_status', 'success')
            ->where('last_imported_at', '>=', $freshCutoff)
            ->count();
        $never = Roaster::where('is_active', true)
            ->whereNull('last_imported_at')
            ->count();
        $stale = max(0, $roastersTotal - $fresh - $never);

        $coffeesTotal = Coffee::available()
            ->whereHas('roaster', fn ($q) => $q->where('is_active', true))
            ->count();

        // Map coverage facets (independent, not a strict partition): how many
        // active roasters are placed on the map, are deliberately online-only,
        // or *should* have a pin but are still missing coordinates.
        $located = Roaster::where('is_active', true)
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->count();
        $onlineOnly = Roaster::where('is_active', true)
            ->where('is_online_only', true)
            ->count();
        $unplaced = Roaster::where('is_active', true)
            ->where('is_online_only', false)
            ->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude'))
            ->count();

        $lastImportedAt = Roaster::where('is_active', true)->max('last_imported_at');

        return response()->json([
            'roasters_total' => $roastersTotal,
            'coffees_total' => $coffeesTotal,
            'last_imported_at' => $lastImportedAt ? Carbon::parse($lastImportedAt)->toIso8601String() : null,
            'freshness' => [
                'fresh' => $fresh,
                'stale' => $stale,
                'never' => $never,
                'fresh_within_days' => $freshWithinDays,
            ],
            'map_coverage' => [
                'located' => $located,
                'online_only' => $onlineOnly,
                'unplaced' => $unplaced,
            ],
        ], 200);
    }

    /** Last-resort favicon fallback via Google's public S2 service. */
    private function googleFaviconUrl(?string $website): ?string
    {
        if (!$website) return null;
        $host = parse_url($website, PHP_URL_HOST);
        if (!$host) return null;
        return 'https://www.google.com/s2/favicons?domain=' . urlencode($host) . '&sz=64';
    }

    /** @return array<int, array{count:int, average:?float}> */
    private function buildRatingMap(array $coffeeIds): array
    {
        if (empty($coffeeIds)) return [];
        $rows = Tasting::query()
            ->select('coffee_id', DB::raw('COUNT(*) as cnt'), DB::raw('AVG(rating) as avg_rating'))
            ->whereIn('coffee_id', $coffeeIds)
            ->where('is_public', true)
            ->whereNotNull('rating')
            ->groupBy('coffee_id')
            ->get();
        $map = [];
        foreach ($rows as $r) {
            $map[$r->coffee_id] = [
                'count' => (int) $r->cnt,
                'average' => $r->avg_rating !== null ? round((float) $r->avg_rating, 1) : null,
            ];
        }
        return $map;
    }

    private function transformRoaster(Roaster $roaster, bool $detail = false, array $ratingMap = []): array
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
            // Q-AR: which cascade step produced the resolved address (or null).
            // The React map uses `is_online_only` to suppress markers for
            // roasters that genuinely have no physical address — otherwise
            // every online-only shop would stack on a city centroid.
            'address_source' => $roaster->address_source,
            'is_online_only' => (bool) $roaster->is_online_only,
            'ships_to' => $roaster->ships_to ?? [],
            'website' => $roaster->website,
            'instagram' => $roaster->instagram,
            // Prefer the scraped favicon URL; otherwise fall back to Google's
            // S2 favicon service which always returns SOMETHING for any
            // domain. This keeps every row visually anchored even before the
            // background scraper has had a chance to run for new roasters.
            'favicon_url' => $roaster->favicon_url ?: $this->googleFaviconUrl($roaster->website),
            'description' => $detail ? $roaster->description : null,
            'has_shipping' => (bool) $roaster->has_shipping,
            'shipping_cost' => $roaster->shipping_cost !== null ? (float) $roaster->shipping_cost : null,
            'free_shipping_over' => $roaster->free_shipping_over !== null ? (float) $roaster->free_shipping_over : null,
            'shipping_notes' => $roaster->shipping_notes,
            'has_subscription' => (bool) $roaster->has_subscription,
            'subscription_notes' => $roaster->subscription_notes,
            // Trust#1: import freshness so the UI can show "updated N days ago"
            // and badge stale / never-imported roasters. The internal error text
            // stays admin-only — we expose just the timestamp + coarse status
            // ('success' | 'empty' | 'error' | null).
            'last_imported_at' => $roaster->last_imported_at?->toIso8601String(),
            'last_import_status' => $roaster->last_import_status,
            'coffees' => $roaster->coffees->map(function ($c) use ($ratingMap) {
                $variants = $c->variants->map(fn ($v) => [
                    'id' => $v->id,
                    'bag_weight_grams' => $v->bag_weight_grams,
                    'source_size_label' => $v->source_size_label,
                    'price' => (float) $v->price,
                    'currency_code' => $v->currency_code ?? 'CAD',
                    'in_stock' => (bool) $v->in_stock,
                    'purchase_link' => $v->purchase_link,
                    'price_per_gram' => $v->price_per_gram,
                    'cents_per_gram' => $v->cents_per_gram,
                ])->values();
                $default = $variants->firstWhere('in_stock', true) ?? $variants->first();
                $rating = $ratingMap[$c->id] ?? ['count' => 0, 'average' => null];
                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'origin' => $c->origin,
                    'process' => $c->process,
                    'roast_level' => $c->roast_level,
                    'varietal' => $c->varietal,
                    'elevation_meters' => $c->elevation_meters,
                    'tasting_notes' => $c->tasting_notes,
                    'description' => $c->description,
                    'product_url' => $c->product_url,
                    'image_url' => $c->image_url,
                    'is_blend' => (bool) $c->is_blend,
                    'is_removed' => $c->removed_at !== null,
                    'best_price_per_gram' => $c->best_price_per_gram,
                    'default_variant' => $default,
                    'variants' => $variants,
                    'rating' => [
                        'count' => $rating['count'],
                        'average' => $rating['average'],
                        'average_stars' => $rating['average'] !== null ? round($rating['average'] / 2, 1) : null,
                    ],
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
