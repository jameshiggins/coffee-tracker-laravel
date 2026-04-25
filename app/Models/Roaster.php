<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Roaster extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'city', 'region', 'country_code', 'street_address', 'postal_code',
        'latitude', 'longitude', 'website', 'instagram', 'description',
        'has_shipping', 'ships_to', 'shipping_cost', 'free_shipping_over', 'shipping_notes',
        'has_subscription', 'subscription_notes', 'is_active',
    ];

    protected $casts = [
        'has_shipping' => 'boolean',
        'has_subscription' => 'boolean',
        'is_active' => 'boolean',
        'shipping_cost' => 'decimal:2',
        'free_shipping_over' => 'decimal:2',
        'latitude' => 'float',
        'longitude' => 'float',
        'ships_to' => 'array',
    ];

    public function coffees(): HasMany
    {
        return $this->hasMany(Coffee::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
