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
        'roaster_id', 'name', 'origin', 'process', 'roast_level',
        'varietal', 'tasting_notes', 'is_blend',
    ];

    protected $casts = [
        'is_blend' => 'boolean',
    ];

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

    public function getBestPricePerGramAttribute(): ?float
    {
        $best = $this->variants
            ->filter(fn ($v) => $v->bag_weight_grams > 0)
            ->map(fn ($v) => $v->price / $v->bag_weight_grams)
            ->min();
        return $best === null ? null : round($best, 4);
    }
}
