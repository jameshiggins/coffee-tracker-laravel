<?php

namespace Tests\Feature;

use App\Services\Http\BlockedUrlException;
use App\Services\Http\SafeHttp;
use App\Services\Http\SsrfGuard;
use Tests\TestCase;

/**
 * H2: the scraping/geocoding subsystem fetches arbitrary third-party URLs.
 * SsrfGuard + SafeHttp must reject any target that resolves to a non-public
 * address (cloud metadata, loopback, RFC1918, link-local, IPv6 ULA, CGNAT)
 * and any non-HTTP(S) scheme — on the initial request AND every redirect hop.
 */
class SsrfProtectionTest extends TestCase
{
    /** @dataProvider blockedIps */
    public function test_non_public_ips_are_blocked(string $ip): void
    {
        $this->assertTrue(SsrfGuard::isBlockedIp($ip), "{$ip} should be blocked");
    }

    public static function blockedIps(): array
    {
        return [
            'loopback v4' => ['127.0.0.1'],
            'cloud metadata' => ['169.254.169.254'],
            'link-local' => ['169.254.10.10'],
            'rfc1918 10/8' => ['10.1.2.3'],
            'rfc1918 172.16/12' => ['172.16.5.5'],
            'rfc1918 192.168/16' => ['192.168.1.1'],
            'cgnat 100.64/10' => ['100.64.0.1'],
            // Boundary pins for the range-compare rewrite: an off-by-one in
            // either bound passes the single mid-range case above.
            'cgnat low edge' => ['100.64.0.0'],
            'cgnat high edge' => ['100.127.255.255'],
            'unspecified' => ['0.0.0.0'],
            'loopback v6' => ['::1'],
            'ula v6' => ['fd00::1'],
            'fly 6pn ula' => ['fdaa:0:1234::3'],
        ];
    }

    /** @dataProvider publicIps */
    public function test_public_ips_are_allowed(string $ip): void
    {
        $this->assertFalse(SsrfGuard::isBlockedIp($ip), "{$ip} should be allowed");
    }

    public static function publicIps(): array
    {
        return [
            'google dns' => ['8.8.8.8'],
            'cloudflare dns' => ['1.1.1.1'],
            'public host' => ['93.184.216.34'],
            'cloudflare v6' => ['2606:4700:4700::1111'],
            // CGN range boundaries from the PUBLIC side: one below the low
            // bound and one past the high bound must stay allowed.
            'just below cgnat' => ['100.63.255.255'],
            'just above cgnat' => ['100.128.0.0'],
        ];
    }

    public function test_assert_url_allowed_rejects_metadata_and_schemes(): void
    {
        // IP-literal hosts → no DNS lookup needed.
        $this->assertThrowsBlocked(fn () => SsrfGuard::assertUrlAllowed('http://169.254.169.254/latest/meta-data/'));
        $this->assertThrowsBlocked(fn () => SsrfGuard::assertUrlAllowed('http://127.0.0.1:8080/'));
        $this->assertThrowsBlocked(fn () => SsrfGuard::assertUrlAllowed('http://[::1]/'));
        $this->assertThrowsBlocked(fn () => SsrfGuard::assertUrlAllowed('file:///etc/passwd'));
        $this->assertThrowsBlocked(fn () => SsrfGuard::assertUrlAllowed('gopher://10.0.0.1/'));

        // A public IP literal passes (no network call).
        SsrfGuard::assertUrlAllowed('https://8.8.8.8/');
        $this->addToAssertionCount(1);
    }

    public function test_safehttp_client_blocks_outbound_to_internal_host(): void
    {
        // The request middleware rejects the URL before any socket is opened,
        // so this never touches the network.
        $this->expectException(BlockedUrlException::class);
        SafeHttp::client(2)->get('http://169.254.169.254/latest/meta-data/');
    }

    private function assertThrowsBlocked(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected BlockedUrlException was not thrown.');
        } catch (BlockedUrlException $e) {
            $this->addToAssertionCount(1);
        }
    }
}
