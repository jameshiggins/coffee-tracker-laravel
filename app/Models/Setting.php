<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Runtime key/value settings, editable from the admin UI. Backs flags that
 * must flip WITHOUT a deploy or machine restart (Fly env changes restart the
 * machine) — currently `verbose_logging`.
 */
class Setting extends Model
{
    public $timestamps = false;
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['key', 'value'];

    /**
     * Per-process memo. Web requests and scheduled commands get a fresh
     * process, so toggles apply instantly there; a long-lived queue:work
     * process picks changes up on its next restart (max-time 3600 caps the
     * staleness). Flush in tests via forget().
     */
    private static array $memo = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (! array_key_exists($key, self::$memo)) {
            try {
                self::$memo[$key] = static::query()->find($key)?->value;
            } catch (\Throwable) {
                // Settings must never take the app down (e.g. table missing
                // mid-migration) — behave as "unset".
                return $default;
            }
        }

        return self::$memo[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
        self::$memo[$key] = (string) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key);

        return $v === null ? $default : in_array(strtolower((string) $v), ['1', 'true', 'on', 'yes'], true);
    }

    public static function verboseLogging(): bool
    {
        return self::bool('verbose_logging');
    }

    public static function forget(string $key): void
    {
        unset(self::$memo[$key]);
    }
}
