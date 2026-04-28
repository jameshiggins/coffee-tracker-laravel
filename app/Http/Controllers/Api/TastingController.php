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
        $data = $request->validate([
            'coffee_id'   => 'required|integer|exists:coffees,id',
            'rating'      => 'nullable|integer|min:1|max:10',
            'notes'       => 'nullable|string|max:5000',
            'brew_method' => 'nullable|string|max:50',
            'tasted_on'   => 'required|date',
            'is_public'   => 'sometimes|boolean',
        ]);
        $data['user_id'] = $request->user()->id;
        $data['is_public'] = $data['is_public'] ?? true;

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
            'rating' => $t->rating,
            'notes' => $t->notes,
            'brew_method' => $t->brew_method,
            'tasted_on' => $t->tasted_on->toDateString(),
            'is_public' => (bool) $t->is_public,
        ];
    }
}
