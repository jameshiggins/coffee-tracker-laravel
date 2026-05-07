<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoffeeVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'coffee_id', 'bag_weight_grams', 'source_size_label', 'price',
        'currency_code', 'in_stock', 'in_stock_changed_at', 'purchase_link',
    ];

    protected $casts = [
        'in_stock' => 'boolean',
        'price' => 'decimal:2',
        'in_stock_changed_at' => 'datetime',
    ];

    public function coffee(): BelongsTo
    {
        return $this->belongsTo(Coffee::class);
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
