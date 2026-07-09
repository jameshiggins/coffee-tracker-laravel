<?php

namespace Tests\Unit\Http;

use App\Services\Http\SafeHttp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SafeHttp::client() transient-failure retry. A single flaky poll (connection
 * reset, DNS blip, read timeout) used to mark a roaster's whole daily import as
 * errored; the client now retries a couple of times before giving up. A 4xx/5xx
 * RESPONSE is not a transient fault — it comes back for the scraper to interpret
 * and must NOT be retried here.
 */
class SafeHttpClientTest extends TestCase
{
    public function test_it_retries_transient_connection_failures_then_succeeds(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new ConnectionException('temporary network blip');
            }

            return Http::response('recovered', 200);
        });

        $response = SafeHttp::client(10)->get('https://roaster.example/products.json');

        $this->assertSame(200, $response->status());
        $this->assertSame('recovered', $response->body());
        $this->assertSame(3, $attempts, 'two failures should be retried and the third attempt served');
    }

    public function test_it_does_not_retry_a_bad_http_status(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            return Http::response('forbidden', 403);
        });

        $response = SafeHttp::client(10)->get('https://roaster.example/products.json');

        $this->assertSame(403, $response->status());
        $this->assertSame(1, $attempts, 'a 403 response is not a transient fault — no retry');
    }

    public function test_a_persistent_connection_failure_still_throws_after_retries(): void
    {
        // The importer's per-roaster try/catch relies on a truly unreachable
        // site still throwing (so it records last_import_error) rather than
        // silently returning null once the retries are spent.
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('host unreachable');
        });

        $this->expectException(ConnectionException::class);

        try {
            SafeHttp::client(10)->get('https://roaster.example/products.json');
        } finally {
            $this->assertSame(3, $attempts, 'should exhaust all attempts before giving up');
        }
    }
}
