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
        'latitude', 'longitude', 'website', 'instagram', 'favicon_url', 'description',
        'has_shipping', 'ships_to', 'shipping_cost', 'free_shipping_over', 'shipping_notes',
        'has_subscription', 'subscription_notes', 'is_active',
        'platform', 'last_imported_at', 'last_import_status', 'last_import_error',
        'import_failing_since',
        'address_source', 'address_verified_at', 'is_online_only', 'google_place_id',
    ];

    protected $casts = [
        'has_shipping' => 'boolean',
        'has_subscription' => 'boolean',
        'is_active' => 'boolean',
        'is_online_only' => 'boolean',
        'shipping_cost' => 'decimal:2',
        'free_shipping_over' => 'decimal:2',
        'latitude' => 'float',
        'longitude' => 'float',
        'ships_to' => 'array',
        'last_imported_at' => 'datetime',
        'import_failing_since' => 'datetime',
        'address_verified_at' => 'datetime',
    ];

    public function coffees(): HasMany
    {
        return $this->hasMany(Coffee::class);
    }

    /**
     * Classify the current import failure so the admin can triage by cause
     * and the auto-deactivation job can target only genuinely-dead domains.
     * Single source of truth for both the Needs Attention view and
     * roasters:auto-deactivate-dead — keep them from drifting.
     *
     *   dead_domain — DNS won't resolve (site gone/rebranded/lapsed domain)
     *   blocked     — reachable but refusing us (401/403 bot-block)
     *   error       — some other import failure
     *   null        — not currently in an error state
     */
    public function importErrorKind(): ?string
    {
        if ($this->last_import_status !== 'error') {
            return null;
        }

        $e = strtolower((string) $this->last_import_error);

        if (str_contains($e, 'could not resolve host') || str_contains($e, 'curl error 6')) {
            return 'dead_domain';
        }
        if (str_contains($e, '401') || str_contains($e, '403') || str_contains($e, 'forbidden')) {
            return 'blocked';
        }

        return 'error';
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
