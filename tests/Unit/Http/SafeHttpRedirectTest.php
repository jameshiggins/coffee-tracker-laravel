<?php

namespace Tests\Unit\Http;

use App\Services\Http\BlockedUrlException;
use App\Services\Http\SafeHttp;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * The redirect-hop revalidation is the one control standing between a
 * scraped third-party URL and Fly's metadata service: a compromised roaster
 * site can 302 the daily importer anywhere. SsrfProtectionTest covers the
 * INITIAL-request guard; this pins the on_redirect callback in
 * SafeHttp::options() — which Http::fake() bypasses entirely (fakes skip
 * the Guzzle handler stack), so it needs a real Guzzle client over a
 * MockHandler. (2026-07 review: top coverage gap.)
 */
class SafeHttpRedirectTest extends TestCase
{
    public function test_a_redirect_hop_to_an_internal_address_is_blocked_before_it_is_followed(): void
    {
        $mock = new MockHandler([
            new Response(301, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            new Response(200, [], 'must never be fetched'),
        ]);

        $client = new Client(array_merge(SafeHttp::options(), [
            'handler' => HandlerStack::create($mock),
        ]));

        $blocked = null;
        try {
            $client->get('http://public-roaster.example/product');
        } catch (\Throwable $e) {
            // Guzzle may surface the callback's exception raw or wrapped in a
            // RequestException — accept either, but it must be present.
            for ($cursor = $e; $cursor !== null; $cursor = $cursor->getPrevious()) {
                if ($cursor instanceof BlockedUrlException) {
                    $blocked = $cursor;
                    break;
                }
            }
            if ($blocked === null) {
                throw $e; // unexpected failure mode — surface it
            }
        }

        $this->assertNotNull($blocked, 'the internal redirect target must raise BlockedUrlException');
        $this->assertSame(1, $mock->count(), 'the redirect target must never actually be requested');
    }

    public function test_a_redirect_hop_to_a_public_address_is_followed_normally(): void
    {
        $mock = new MockHandler([
            new Response(301, ['Location' => 'http://public-roaster.example/moved']),
            new Response(200, [], 'final body'),
        ]);

        $client = new Client(array_merge(SafeHttp::options(), [
            'handler' => HandlerStack::create($mock),
        ]));

        $response = $client->get('http://public-roaster.example/product');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('final body', (string) $response->getBody());
        $this->assertSame(0, $mock->count(), 'both hops served');
    }
}
