<?php

namespace App\Services\Http;

use App\Services\Scraping\Shared;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The single guarded HTTP client every scraper + geocoder uses for outbound
 * requests. Layers SSRF protection on top of Shared::clientOptions() (CA
 * bundle + User-Agent):
 *
 *   - Initial request URL is validated by a request middleware.
 *   - Every redirect hop is re-validated by the allow_redirects on_redirect
 *     callback (Guzzle follows redirects inside the handler, below the
 *     request middleware, so the middleware alone would NOT see them).
 *   - Redirects are capped and restricted to http/https.
 *
 * Usage mirrors the old call sites — swap
 *   Http::timeout($t)->withOptions(Shared::clientOptions())
 * for
 *   SafeHttp::client($t)
 * and keep the rest of the chain (->acceptJson()->get($url), …) unchanged.
 */
final class SafeHttp
{
    private const MAX_REDIRECTS = 5;

    /** Total attempts for a transient failure, and the base backoff (ms). */
    private const RETRY_ATTEMPTS = 3;
    private const RETRY_BACKOFF_MS = 300;

    public static function options(): array
    {
        $opts = Shared::clientOptions();

        $opts['allow_redirects'] = [
            'max' => self::MAX_REDIRECTS,
            'protocols' => ['http', 'https'],
            'referer' => false,
            'track_redirects' => false,
            'on_redirect' => function ($request, $response, $uri) {
                SsrfGuard::assertUrlAllowed((string) $uri);
            },
        ];

        return $opts;
    }

    public static function client(int $timeout): PendingRequest
    {
        return Http::timeout($timeout)
            ->withOptions(self::options())
            // Retry transient network failures (connection resets, DNS blips,
            // read timeouts) with a small linear backoff. A single flaky poll
            // used to doom a roaster's whole daily import — retrying recovers it.
            //
            // Only ConnectionExceptions retry here. throw:false is essential:
            // Laravel's retry surfaces a failed RESPONSE (4xx/5xx) as a
            // RequestException to evaluate $when, and would otherwise rethrow it
            // — but every scraper expects a bad status to come back as a normal
            // response ($response->ok() === false) it can branch on, not an
            // exception. throw:false returns that response untouched. A genuine
            // ConnectionException has no response, so it still propagates after
            // the retries are spent, and the importer records the roaster error.
            // An SsrfGuard block is NOT a transient fault, so it isn't retried.
            ->retry(self::RETRY_ATTEMPTS, self::RETRY_BACKOFF_MS, function ($exception) {
                return $exception instanceof \Illuminate\Http\Client\ConnectionException;
            }, throw: false)
            ->withRequestMiddleware(function ($request) {
                SsrfGuard::assertUrlAllowed((string) $request->getUri());

                return $request;
            });
    }
}
