<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use RuntimeException;
use Tests\TestCase;

/**
 * Production error tracking: the Sentry integration wiring.
 *
 * These tests cover *our* integration, not the SDK internals — chiefly the
 * safety property that matters operationally: with no DSN configured (local
 * dev, the test suite, any key-less environment) reporting an exception must be
 * a silent no-op and must never itself throw. The DSN is only ever set as a
 * Fly secret in production.
 */
class SentryErrorTrackingTest extends TestCase
{
    public function test_sentry_config_is_published_and_registered(): void
    {
        $config = config('sentry');

        $this->assertIsArray($config);
        $this->assertArrayHasKey('dsn', $config);
    }

    public function test_no_dsn_is_configured_in_the_test_environment(): void
    {
        // The suite must never ship events to a real project. "No DSN" is what
        // matters, not its exact falsy shape: locally the key is absent so
        // env() yields null, while CI copies .env.example (SENTRY_LARAVEL_DSN=)
        // so it yields ''. Sentry treats both as "disabled" — assert emptiness.
        $this->assertEmpty(config('sentry.dsn'));
    }

    public function test_reporting_an_exception_is_a_safe_noop_without_a_dsn(): void
    {
        $handler = app(ExceptionHandler::class);

        // The reportable callback runs Integration::captureUnhandledException;
        // with no DSN bound this must complete without throwing.
        $handler->report(new RuntimeException('error-tracking smoke test'));

        $this->assertTrue(true);
    }

    public function test_health_probe_is_excluded_from_tracing(): void
    {
        // The uptime monitor polls GET /up constantly; it must not flood Sentry.
        $this->assertContains('/up', config('sentry.ignore_transactions', []));
    }
}
