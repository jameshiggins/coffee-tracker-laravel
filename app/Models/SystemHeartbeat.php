<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ops monitoring: a key→timestamp liveness store read by the GET /up health
 * check. See the create_system_heartbeats migration for the signal names.
 */
class SystemHeartbeat extends Model
{
    protected $fillable = ['key', 'last_seen_at', 'meta'];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'meta' => 'array',
    ];

    /**
     * Record that a named signal is alive right now. Idempotent upsert keyed
     * by name. Never throws into the caller's hot path — monitoring must not
     * be able to break the thing it monitors.
     */
    public static function ping(string $key, ?array $meta = null): void
    {
        try {
            static::updateOrCreate(
                ['key' => $key],
                ['last_seen_at' => Carbon::now(), 'meta' => $meta],
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** When was this signal last seen, or null if never. */
    public static function lastSeen(string $key): ?Carbon
    {
        return static::where('key', $key)->first()?->last_seen_at;
    }
}
