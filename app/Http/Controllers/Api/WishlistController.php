<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coffee;
use App\Models\Wishlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Q10: per-user wishlists. Private — only the owner sees the contents.
 * Aggregate counts are exposed at the coffee level (CoffeeApiController)
 * without leaking who wishlisted what.
 */
class WishlistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = $request->user()->wishlist()
            ->with('coffee.roaster')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'items' => $items->map(fn ($w) => [
                'id' => $w->id,
                'created_at' => $w->created_at->toIso8601String(),
                'coffee' => $w->coffee ? [
                    'id' => $w->coffee->id,
                    'name' => $w->coffee->name,
                    'image_url' => $w->coffee->image_url,
                    'is_removed' => $w->coffee->removed_at !== null,
                    'roaster' => [
                        'id' => $w->coffee->roaster->id,
                        'name' => $w->coffee->roaster->name,
                        'slug' => $w->coffee->roaster->slug,
                    ],
                ] : null,
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'coffee_id' => 'required|integer|exists:coffees,id',
        ]);

        // Idempotent: re-adding an existing wishlist is a no-op so the
        // heart-icon toggle never throws on a double click.
        $wish = Wishlist::firstOrCreate([
            'user_id'   => $request->user()->id,
            'coffee_id' => $data['coffee_id'],
        ]);

        return response()->json([
            'wishlist' => ['id' => $wish->id, 'coffee_id' => $wish->coffee_id],
        ], 201);
    }

    public function destroy(Request $request, Coffee $coffee): JsonResponse
    {
        $request->user()->wishlist()->where('coffee_id', $coffee->id)->delete();
        return response()->json(null, 204);
    }
}
