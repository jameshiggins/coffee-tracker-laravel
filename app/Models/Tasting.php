<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tasting extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'coffee_id', 'rating', 'notes', 'brew_method', 'tasted_on', 'is_public',
        'flagged_at', 'flagged_by_user_id', 'coffee_snapshot',
    ];

    protected $casts = [
        'rating' => 'integer',
        'is_public' => 'boolean',
        'tasted_on' => 'date',
        'flagged_at' => 'datetime',
        'coffee_snapshot' => 'array',
    ];

    /**
     * Build a frozen snapshot of the linked coffee's current state.
     * Called at tasting creation/update so the historical record survives
     * future seasonal rotations of the same coffee_id.
     */
    public static function buildCoffeeSnapshot(Coffee $c): array
    {
        $c->loadMissing('roaster');
        return [
            'name' => $c->name,
            'origin' => $c->origin,
            'process' => $c->process,
            'roast_level' => $c->roast_level,
            'varietal' => $c->varietal,
            'tasting_notes' => $c->tasting_notes,
            'image_url' => $c->image_url,
            'is_blend' => (bool) $c->is_blend,
            'roaster_name' => $c->roaster?->name,
            'roaster_slug' => $c->roaster?->slug,
            'snapshotted_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Aggregate public rating per coffee in ONE grouped query (avoids N+1).
     * Returns [coffee_id => ['count'=>int, 'average'=>?float, 'average_stars'=>?float]].
     */
    public static function ratingMapFor(array $coffeeIds): array
    {
        if (empty($coffeeIds)) {
            return [];
        }

        $rows = static::query()
            ->selectRaw('coffee_id, COUNT(*) as cnt, AVG(rating) as avg_rating')
            ->whereIn('coffee_id', $coffeeIds)
            ->where('is_public', true)
            ->whereNotNull('rating')
            ->groupBy('coffee_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $avg = $row->avg_rating !== null ? round((float) $row->avg_rating, 1) : null;
            $map[$row->coffee_id] = [
                'count' => (int) $row->cnt,
                'average' => $avg,
                'average_stars' => $avg !== null ? round($avg / 2, 1) : null,
            ];
        }

        return $map;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coffee(): BelongsTo
    {
        return $this->belongsTo(Coffee::class);
    }
}
