<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coffee;
use App\Models\Tasting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TastingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tastings = $request->user()->tastings()
            ->with('coffee.roaster')
            ->orderByDesc('tasted_on')
            ->get();

        return response()->json(['tastings' => $tastings->map(fn ($t) => $this->transform($t))->values()]);
    }

    public function publicForCoffee(Coffee $coffee): JsonResponse
    {
        $tastings = $coffee->tastings()
            ->where('is_public', true)
            ->with('user:id,display_name,avatar_url')
            ->orderByDesc('tasted_on')
            ->get();

        return response()->json([
            'tastings' => $tastings->map(fn ($t) => [
                'id' => $t->id,
                'rating' => $t->rating,
                'notes' => $t->notes,
                'brew_method' => $t->brew_method,
                'tasted_on' => $t->tasted_on->toDateString(),
                'user' => [
                    'id' => $t->user->id,
                    'display_name' => $t->user->display_name,
                    'avatar_url' => $t->user->avatar_url,
                ],
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // Q17 rate limit: brand-new accounts can post at most 5 tastings/day
        // for their first 7 days. Stops dump-and-bail spam attacks.
        if ($user->created_at->isAfter(now()->subDays(7))) {
            $count = $user->tastings()->where('created_at', '>=', now()->subDay())->count();
            if ($count >= 5) {
                return response()->json([
                    'error' => 'New accounts are limited to 5 tastings per day for the first week.',
                ], 429);
            }
        }

        $data = $request->validate([
            'coffee_id'   => 'required|integer|exists:coffees,id',
            'rating'      => 'nullable|integer|min:1|max:10',
            'notes'       => 'nullable|string|max:5000',
            'brew_method' => 'nullable|string|max:50',
            'tasted_on'   => 'required|date',
            'is_public'   => 'sometimes|boolean',
        ]);
        $data['user_id'] = $user->id;
        $data['is_public'] = $data['is_public'] ?? true;
        // Freeze the bean's current state. The roaster might rotate this
        // product slot to a different coffee in 6 months; the user's
        // tasting record should survive intact.
        $coffee = Coffee::with('roaster')->find($data['coffee_id']);
        $data['coffee_snapshot'] = Tasting::buildCoffeeSnapshot($coffee);

        $tasting = Tasting::create($data);

        return response()->json(['tasting' => $this->transform($tasting)], 201);
    }

    public function update(Request $request, Tasting $tasting): JsonResponse
    {
        $this->authorize('update', $tasting);

        $data = $request->validate([
            'rating'      => 'nullable|integer|min:1|max:10',
            'notes'       => 'nullable|string|max:5000',
            'brew_method' => 'nullable|string|max:50',
            'tasted_on'   => 'sometimes|date',
            'is_public'   => 'sometimes|boolean',
        ]);
        $tasting->update($data);

        return response()->json(['tasting' => $this->transform($tasting->fresh())]);
    }

    public function destroy(Request $request, Tasting $tasting): JsonResponse
    {
        $this->authorize('delete', $tasting);
        $tasting->delete();
        return response()->json(null, 204);
    }

    /**
     * Q17: anyone (auth optional) can flag a public tasting for review.
     * No auto-hide on flag — admin reviews via the moderation queue and
     * makes the soft-delete decision. Idempotent: refl agging is a no-op.
     */
    public function report(Request $request, Tasting $tasting): JsonResponse
    {
        if (!$tasting->is_public) {
            return response()->json(['error' => 'Not found'], 404);
        }
        if ($tasting->flagged_at !== null) {
            return response()->json(['ok' => true]); // already flagged
        }
        $tasting->forceFill([
            'flagged_at' => now(),
            'flagged_by_user_id' => $request->user()?->id,
        ])->save();
        return response()->json(['ok' => true]);
    }

    private function transform(Tasting $t): array
    {
        // Re-load relationships if not already eager-loaded — covers the
        // store() / update() paths where the controller called transform()
        // on a freshly-constructed model.
        $t->loadMissing('coffee.roaster');

        return [
            'id' => $t->id,
            'coffee_id' => $t->coffee_id,
            'coffee' => $t->coffee ? [
                'id' => $t->coffee->id,
                'name' => $t->coffee->name,
                'image_url' => $t->coffee->image_url,
                'is_removed' => $t->coffee->removed_at !== null,
                'roaster' => [
                    'name' => $t->coffee->roaster->name,
                    'slug' => $t->coffee->roaster->slug,
                ],
            ] : null,
            // Coffee state at the time the tasting was recorded. Display
            // surfaces should prefer this over `coffee` when present —
            // it's the historical truth, not what's on the page now.
            'coffee_snapshot' => $t->coffee_snapshot,
            'coffee_changed' => $this->hasCoffeeChanged($t),
            'rating' => $t->rating,
            'notes' => $t->notes,
            'brew_method' => $t->brew_method,
            'tasted_on' => $t->tasted_on->toDateString(),
            'is_public' => (bool) $t->is_public,
        ];
    }

    /**
     * Did the live coffee diverge from the snapshot in any of the
     * fields the user actually sees? Cheap field-by-field compare;
     * description deliberately excluded (it changes too often to be a
     * meaningful signal).
     */
    private function hasCoffeeChanged(Tasting $t): bool
    {
        if (!$t->coffee_snapshot || !$t->coffee) return false;
        $live = $t->coffee;
        $snap = $t->coffee_snapshot;
        $fields = ['name', 'origin', 'process', 'roast_level', 'varietal'];
        foreach ($fields as $f) {
            if (($snap[$f] ?? null) !== $live->$f) return true;
        }
        if (($snap['is_blend'] ?? null) !== (bool) $live->is_blend) return true;
        return false;
    }
}
