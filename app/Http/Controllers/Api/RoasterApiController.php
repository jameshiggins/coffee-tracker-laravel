<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coffee;
use App\Models\CoffeeVariant;
use App\Models\Roaster;
use App\Models\Tasting;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class RoasterApiController extends Controller
{
    public function index(Request $request): SymfonyResponse
    {
        // H6 interim (2026-07 review P2): server-side caching alone still
        // re-transferred the full directory on every SPA visit. The ETag is
        // the same content version that keys the server cache, so a matching
        // If-None-Match short-circuits to an empty 304 before any DB/cache
        // assembly work. max-age lets the browser skip even the revalidation
        // round-trip for 5 minutes.
        // Computed ONCE per request and passed down: it serves as both the
        // ETag and the cache key, and recomputing (8 aggregate queries) per
        // use doubled the hot path's query cost (review finding). A property
        // memo is NOT safe here — Laravel reuses the controller instance on
        // the route object, so it would leak across in-process requests
        // (tests, Octane).
        $version = $this->directoryVersion();
        $etag = '"'.$version.'"';
        $cacheControl = 'public, max-age=300';

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag, 'Cache-Control' => $cacheControl]);
        }

        // The whole directory changes at most a few times a day (the nightly
        // import + occasional admin edits), but this is the SPA's heaviest,
        // most-hit read. Cache the fully-assembled payload, keyed on a content
        // version so any write transparently busts it.
        $roasters = $this->cacheDirectory('api:roasters:index', $version, function () {
            $roasters = Roaster::with(['coffees' => fn ($q) => $q->whereNull('removed_at'), 'coffees.variants'])
                ->where('is_active', true)
                ->orderBy('name')
                ->get();

            // Single grouped query for aggregate ratings across every coffee
            // shown in this response. Cheaper than N+1 sub-queries.
            $ratingMap = $this->buildRatingMap($roasters->flatMap(fn ($r) => $r->coffees->pluck('id'))->all());

            return $roasters->map(fn ($r) => $this->transformRoaster($r, false, $ratingMap))->values()->all();
        });

        // JSON_INVALID_UTF8_SUBSTITUTE: render U+FFFD in place of invalid
        // UTF-8 bytes instead of throwing InvalidArgumentException (which
        // 500s the whole endpoint when any single coffee has bad bytes).
        // Import-time sanitize in RoasterImporter prevents new bad bytes;
        // this defends against the existing tail of historical rows.
        return response()->json([
            'roasters' => $roasters,
        ], 200, ['ETag' => $etag, 'Cache-Control' => $cacheControl], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public function show(Roaster $roaster): JsonResponse
    {
        // A deactivated roaster is a moderation "hide" — 404 instead of
        // serving it (index already filters is_active; show must too).
        abort_if(! $roaster->is_active, 404);

        $roaster->load([
            'coffees' => fn ($q) => $q->whereNull('removed_at'),
            'coffees.variants',
        ]);
        $ratingMap = $this->buildRatingMap($roaster->coffees->pluck('id')->all());
        return response()->json($this->transformRoaster($roaster, true, $ratingMap), 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Cache an assembled directory payload, keyed on a content version so any
     * roaster/coffee/variant/tasting write busts it automatically. Bypassed
     * under the test suite for determinism (the array cache store persists
     * across tests in-process and would collide on the version key).
     */
    private function cacheDirectory(string $key, string $version, Closure $build): mixed
    {
        if (app()->runningUnitTests()) {
            return $build();
        }

        return Cache::remember($key . ':' . $version, now()->addHours(6), $build);
    }

    private function directoryVersion(): string
    {
        // Counts catch deletions that leave max(updated_at) untouched —
        // e.g. admin-deleting a non-newest variant. Every table serialized
        // into the payload needs BOTH signals (2026-07 review P3).
        return md5(implode('|', [
            (string) Roaster::max('updated_at'),
            (string) Coffee::max('updated_at'),
            (string) CoffeeVariant::max('updated_at'),
            (string) Tasting::max('updated_at'),
            (string) Roaster::count(),
            (string) Coffee::count(),
            (string) CoffeeVariant::count(),
            (string) Tasting::count(),
        ]));
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
        return response()->json($this->cacheDirectory('api:stats', $this->directoryVersion(), fn () => $this->buildStats()), 200);
    }

    /** @return array<string, mixed> */
    private function buildStats(): array
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

        return [
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
        ];
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
