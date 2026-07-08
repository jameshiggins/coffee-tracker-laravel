<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The admin-viewable operational log (/admin/logs).
 *
 * Prod's default log channel is stderr — great for `fly logs`, invisible to
 * the operator's browser. Every event recorded here is ALSO mirrored to the
 * standard Laravel logger, so nothing is lost from the Fly stream; this
 * table is the browsable, filterable copy.
 *
 * Levels:
 *   error / warning / info  → always recorded (info doubles as the admin
 *                             audit trail: who did what, when)
 *   debug                   → recorded only while the `verbose_logging`
 *                             setting is ON (toggled from /admin/logs)
 *
 * Writes are best-effort: telemetry must never break the request that
 * produced it. Rows are pruned by `logs:prune` (scheduled daily).
 */
class AdminLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['level', 'event', 'message', 'context', 'created_at'];

    protected $casts = [
        'context' => 'array',
        'created_at' => 'datetime',
    ];

    public static function debug(string $event, string $message, array $context = []): void
    {
        self::write('debug', $event, $message, $context);
    }

    public static function info(string $event, string $message, array $context = []): void
    {
        self::write('info', $event, $message, $context);
    }

    public static function warning(string $event, string $message, array $context = []): void
    {
        self::write('warning', $event, $message, $context);
    }

    public static function error(string $event, string $message, array $context = []): void
    {
        self::write('error', $event, $message, $context);
    }

    public static function write(string $level, string $event, string $message, array $context = []): void
    {
        // Mirror to the standard logger first — stderr in prod, laravel.log
        // in dev — so the Fly stream stays complete regardless of the
        // verbose toggle or a DB hiccup.
        try {
            Log::log($level, "[{$event}] {$message}", $context);
        } catch (\Throwable) {
            // Even the mirror must not break the caller.
        }

        if ($level === 'debug' && ! Setting::verboseLogging()) {
            return;
        }

        try {
            static::create([
                'level' => $level,
                'event' => $event,
                'message' => mb_substr($message, 0, 2000),
                'context' => $context ?: null,
                'created_at' => Carbon::now(),
            ]);
        } catch (\Throwable) {
            // Best-effort: never let telemetry take down the operation.
        }
    }
}
