<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coffee extends Model
{
    use HasFactory;

    protected $fillable = [
        'roaster_id', 'source_id', 'name', 'origin', 'process', 'roast_level',
        'varietal', 'elevation_meters', 'tasting_notes', 'description',
        'product_url', 'image_url', 'is_blend', 'best_cents_per_gram', 'removed_at',
    ];

    protected $casts = [
        'is_blend' => 'boolean',
        'elevation_meters' => 'integer',
        'best_cents_per_gram' => 'integer',
        'removed_at' => 'datetime',
    ];

    /** Scope: only currently-listed coffees (imported and not soft-removed). */
    public function scopeAvailable($query)
    {
        return $query->whereNull('removed_at');
    }

    /** Convenience for views that need the boolean. */
    public function isAvailable(): bool
    {
        return $this->removed_at === null;
    }

    public function roaster(): BelongsTo
    {
        return $this->belongsTo(Roaster::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(CoffeeVariant::class)->orderBy('bag_weight_grams');
    }

    public function tastings(): HasMany
    {
        return $this->hasMany(Tasting::class);
    }

    public function getCheapestVariantAttribute(): ?CoffeeVariant
    {
        return $this->variants->sortBy('price')->first();
    }

    /**
     * Recompute and persist best_cents_per_gram from the (loaded) variants.
     * Called by the importer after syncing variants so the indexed column the
     * /api/coffees endpoint sorts/filters on stays in lockstep with pricing.
     */
    public function refreshBestCentsPerGram(): void
    {
        $best = $this->best_price_per_gram; // in-stock-aware, dollars/gram
        $this->forceFill([
            'best_cents_per_gram' => $best === null ? null : (int) round($best * 100),
        ])->save();
    }

    public function getBestPricePerGramAttribute(): ?float
    {
        // The directory's premise is "what's in stock right now", so the
        // headline per-gram price must reflect a bag the user can actually
        // buy. Restrict to in-stock variants; fall back to all variants only
        // when nothing is in stock (so a fully-OOS coffee still shows a price).
        $inStock = $this->variants->where('in_stock', true);
        $pool = $inStock->isNotEmpty() ? $inStock : $this->variants;

        $best = $pool
            ->filter(fn ($v) => $v->bag_weight_grams > 0)
            ->map(fn ($v) => $v->price / $v->bag_weight_grams)
            ->min();
        return $best === null ? null : round($best, 4);
    }
}
