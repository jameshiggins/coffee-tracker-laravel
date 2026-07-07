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
            ->withRequestMiddleware(function ($request) {
                SsrfGuard::assertUrlAllowed((string) $request->getUri());

                return $request;
            });
    }
}
