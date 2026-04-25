<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceHistory extends Model
{
    use HasFactory;

    protected $table = 'price_history';

    protected $fillable = ['coffee_variant_id', 'price', 'in_stock', 'recorded_at'];

    protected $casts = [
        'price' => 'decimal:2',
        'in_stock' => 'boolean',
        'recorded_at' => 'datetime',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(CoffeeVariant::class, 'coffee_variant_id');
    }
}
