<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Sentry\Laravel\Integration;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        // Production exception alerting. Reports uncaught exceptions to Sentry
        // when SENTRY_LARAVEL_DSN is set (a Fly secret in prod); a safe no-op
        // when it's empty, so local dev and any key-less environment stay
        // silent. Sentry dedupes within a request, and config/sentry.php already
        // ignores the GET /up health probe so the uptime monitor's polling
        // never floods the error feed.
        $this->reportable(function (Throwable $e) {
            Integration::captureUnhandledException($e);
        });
    }
}
