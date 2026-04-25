<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoffeeVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'coffee_id', 'bag_weight_grams', 'price', 'in_stock', 'purchase_link', 'is_default',
    ];

    protected $casts = [
        'in_stock' => 'boolean',
        'is_default' => 'boolean',
        'price' => 'decimal:2',
    ];

    public function coffee(): BelongsTo
    {
        return $this->belongsTo(Coffee::class);
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(PriceHistory::class)->orderBy('recorded_at');
    }

    public function getPricePerGramAttribute(): float
    {
        return $this->bag_weight_grams > 0
            ? round($this->price / $this->bag_weight_grams, 4)
            : 0;
    }

    public function getCentsPerGramAttribute(): float
    {
        return $this->bag_weight_grams > 0
            ? round(($this->price / $this->bag_weight_grams) * 100, 1)
            : 0;
    }
}
