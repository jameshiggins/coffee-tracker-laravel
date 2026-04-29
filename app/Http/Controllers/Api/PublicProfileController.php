<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tasting;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * Public surfaces for Q9: /t/{id} permalinks and /u/{display_name} profiles.
 * Both surfaces respect the per-tasting is_public flag and never expose
 * private content.
 */
class PublicProfileController extends Controller
{
    /** GET /api/users/{display_name} — public profile + their public tastings. */
    public function showByDisplayName(string $displayName): JsonResponse
    {
        $user = User::where('display_name', $displayName)->firstOrFail();

        $tastings = $user->tastings()
            ->where('is_public', true)
            ->with('coffee.roaster')
            ->orderByDesc('tasted_on')
            ->limit(100)
            ->get();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'display_name' => $user->display_name,
                'avatar_url' => $user->avatar_url,
            ],
            'tastings' => $tastings->map(fn ($t) => $this->transformTasting($t))->values(),
        ]);
    }

    /** GET /api/tastings/{id} — single tasting permalink. 404 when private. */
    public function showTasting(Tasting $tasting): JsonResponse
    {
        if (!$tasting->is_public) {
            return response()->json(['error' => 'Not found'], 404);
        }
        $tasting->load('user', 'coffee.roaster');

        return response()->json([
            'tasting' => array_merge(
                $this->transformTasting($tasting),
                ['user' => [
                    'id' => $tasting->user->id,
                    'display_name' => $tasting->user->display_name,
                    'avatar_url' => $tasting->user->avatar_url,
                ]]
            ),
        ]);
    }

    private function transformTasting(Tasting $t): array
    {
        $c = $t->coffee;
        return [
            'id' => $t->id,
            'rating' => $t->rating,
            'notes' => $t->notes,
            'brew_method' => $t->brew_method,
            'tasted_on' => $t->tasted_on->toDateString(),
            'coffee' => $c ? [
                'id' => $c->id,
                'name' => $c->name,
                'image_url' => $c->image_url,
                'is_removed' => $c->removed_at !== null,
                'roaster' => [
                    'id' => $c->roaster->id,
                    'name' => $c->roaster->name,
                    'slug' => $c->roaster->slug,
                ],
            ] : null,
        ];
    }
}
