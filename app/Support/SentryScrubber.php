<?php

namespace App\Support;

use Sentry\Event;

/**
 * Defense-in-depth: strip credential-shaped keys from any Sentry event
 * payload before it leaves the process, regardless of where they were
 * captured. Complements sentry.max_request_body_size=none, which already
 * keeps request bodies off events captured through the normal request path.
 *
 * Referenced from config/sentry.php as a static callable
 * ([self::class, 'scrub']) rather than a closure ON PURPOSE: config values
 * must survive `php artisan config:cache` (run by docker/entrypoint.sh on
 * every prod boot), which var_export()s the config — closures fatal with
 * "Call to undefined method Closure::__set_state()" and crash-loop the
 * machine. Class-string arrays export cleanly. Guarded by
 * ConfigCacheableTest.
 */
class SentryScrubber
{
    /** Keys whose values must never reach Sentry. */
    private const FILTERED_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
    ];

    public static function scrub(Event $event): ?Event
    {
        $request = $event->getRequest();

        if (isset($request['data']) && is_array($request['data'])) {
            foreach (self::FILTERED_KEYS as $key) {
                if (array_key_exists($key, $request['data'])) {
                    $request['data'][$key] = '[filtered]';
                }
            }
            $event->setRequest($request);
        }

        return $event;
    }
}
