<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Roaster;
use App\Models\RoasterFavorite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-user pinned/favorite roasters. Private — only the owner sees the
 * list. Mirrors WishlistController's contract (idempotent add, ownership-
 * scoped delete) so the two "save this" features behave identically.
 */
class FavoriteRoasterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = $request->user()->favoriteRoasters()
            ->with('roaster')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'items' => $items->map(fn ($f) => [
                'id' => $f->id,
                'created_at' => $f->created_at->toIso8601String(),
                'roaster' => $f->roaster ? [
                    'id' => $f->roaster->id,
                    'name' => $f->roaster->name,
                    'slug' => $f->roaster->slug,
                    'favicon_url' => $f->roaster->favicon_url,
                    'city' => $f->roaster->city,
                    'region' => $f->roaster->region,
                ] : null,
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'roaster_id' => 'required|integer|exists:roasters,id',
        ]);

        // Idempotent: re-pinning is a no-op so the toggle never throws on a
        // double click (same contract as the wishlist heart).
        $favorite = RoasterFavorite::firstOrCreate([
            'user_id'    => $request->user()->id,
            'roaster_id' => $data['roaster_id'],
        ]);

        return response()->json([
            'favorite' => ['id' => $favorite->id, 'roaster_id' => $favorite->roaster_id],
        ], 201);
    }

    public function destroy(Request $request, Roaster $roaster): JsonResponse
    {
        $request->user()->favoriteRoasters()->where('roaster_id', $roaster->id)->delete();

        return response()->json(null, 204);
    }
}
