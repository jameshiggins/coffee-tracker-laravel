<?php

namespace Tests\Feature;

use App\Support\SentryScrubber;
use Sentry\Event;
use Tests\TestCase;

/**
 * Guard: the config repository must stay `php artisan config:cache`-safe.
 *
 * docker/entrypoint.sh runs config:cache on EVERY prod boot under
 * `set -euo pipefail`. config:cache var_export()s the whole config tree, and
 * closures fatal there ("Call to undefined method Closure::__set_state()"),
 * which crash-loops the single Fly machine. A `before_send` closure in
 * config/sentry.php did exactly this (caught in the 2026-07 stage-one
 * review before it deployed) — it is now the static callable
 * App\Support\SentryScrubber. CI additionally runs the real
 * config:cache/route:cache as a smoke step; this test catches offenders in
 * the fast feedback loop.
 */
class ConfigCacheableTest extends TestCase
{
    public function test_config_tree_contains_no_closures(): void
    {
        $offenders = [];

        $walk = function ($value, string $path) use (&$walk, &$offenders): void {
            if ($value instanceof \Closure) {
                $offenders[] = $path;

                return;
            }
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    $walk($item, $path === '' ? (string) $key : "{$path}.{$key}");
                }
            }
        };

        $walk(config()->all(), '');

        $this->assertSame(
            [],
            $offenders,
            'Closures found in config at: '.implode(', ', $offenders)
            .' — config:cache cannot serialize closures; use a [Class::class, \'method\'] callable instead.'
        );
    }

    public function test_sentry_before_send_is_the_static_scrubber_not_a_closure(): void
    {
        $callback = config('sentry.before_send');

        $this->assertIsCallable($callback);
        $this->assertNotInstanceOf(\Closure::class, $callback);
        $this->assertSame([SentryScrubber::class, 'scrub'], $callback);
    }

    public function test_scrubber_filters_credential_keys_but_leaves_the_rest(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['data' => [
            'password' => 'hunter2',
            'password_confirmation' => 'hunter2',
            'current_password' => 'old-secret',
            'token' => 'reset-token-abc',
            'email' => 'user@example.com',
        ]]);

        $out = SentryScrubber::scrub($event);

        $data = $out->getRequest()['data'];
        $this->assertSame('[filtered]', $data['password']);
        $this->assertSame('[filtered]', $data['password_confirmation']);
        $this->assertSame('[filtered]', $data['current_password']);
        $this->assertSame('[filtered]', $data['token']);
        $this->assertSame('user@example.com', $data['email'], 'non-credential keys pass through');
    }

    public function test_scrubber_is_a_no_op_for_events_without_request_data(): void
    {
        $event = Event::createEvent();

        $this->assertSame($event, SentryScrubber::scrub($event));
    }
}
