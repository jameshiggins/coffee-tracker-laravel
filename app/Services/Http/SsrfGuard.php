<?php

namespace App\Services\Http;

/**
 * SSRF defense for the scraping/geocoding subsystem.
 *
 * This app's entire job is fetching arbitrary third-party URLs (roaster
 * homepages, product feeds, favicons, contact pages). Without a guard, a
 * malicious or compromised roaster site can point us — directly or via an
 * HTTP redirect — at a private/internal address (cloud metadata at
 * 169.254.169.254, the Fly 6PN ULA range, RFC1918 hosts, loopback) and use
 * us as a confused-deputy probe, with response bodies surfaced back to the
 * operator.
 *
 * assertUrlAllowed() resolves the target host and rejects any address that
 * is not publicly routable. It is enforced on BOTH the initial request
 * (via SafeHttp::client()'s request middleware) and every redirect hop
 * (via the allow_redirects on_redirect callback).
 *
 * Residual risk: DNS rebinding (the host could resolve to a public IP at
 * check time and a private IP at connect time). Fully closing that needs
 * IP pinning at the socket layer; for this app's threat model, resolve-and-
 * reject is the standard, proportionate mitigation.
 */
final class SsrfGuard
{
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /** Validate a full URL: scheme allowlist + resolved-host check. */
    public static function assertUrlAllowed(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false) {
            self::block("Blocked unparseable URL: {$url}");
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            self::block("Blocked non-HTTP(S) URL scheme '{$scheme}': {$url}");
        }

        $host = $parts['host'] ?? '';
        $host = trim($host, '[]'); // strip IPv6 literal brackets
        if ($host === '') {
            self::block("Blocked URL with no host: {$url}");
        }

        self::assertHostAllowed($host);
    }

    /** Resolve a host and reject if any resolved address is non-public. */
    public static function assertHostAllowed(string $host): void
    {
        // IP-literal hosts are checked directly — no DNS, fully deterministic,
        // so the dangerous literals (169.254.169.254, 127.0.0.1, ::1, …) are
        // always rejected, including under the test suite.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (self::isBlockedIp($host)) {
                self::block("Blocked request to non-public address {$host}.");
            }

            return;
        }

        // Hostname: resolving means a real DNS lookup. Skip it under the test
        // suite to keep tests hermetic (no network) and deterministic — the
        // same test-aware shortcut the geocoder/address scrapers already use.
        if (app()->runningUnitTests()) {
            return;
        }

        $ips = self::resolve($host);

        // Unresolvable host can't be connected to, so it's not an SSRF target;
        // let the transport surface the failure rather than masking it.
        foreach ($ips as $ip) {
            if (self::isBlockedIp($ip)) {
                self::block("Blocked request to non-public address {$ip} (host {$host}).");
            }
        }
    }

    /**
     * Record the security event, then refuse. Every SSRF block is
     * operator-visible in /admin/logs — a compromised roaster site planting
     * internal URLs should not fail silently.
     */
    private static function block(string $message): never
    {
        \App\Models\AdminLog::warning('security.ssrf.blocked', $message);

        throw new BlockedUrlException($message);
    }

    /**
     * Is this IP outside the publicly-routable range? Uses PHP's built-in
     * reserved/private range filters (loopback 127/8 + ::1, link-local
     * 169.254/16 + fe80::/10 incl. cloud metadata, RFC1918 10/172.16/192.168,
     * IPv6 ULA fc00::/7 incl. Fly's fdaa::/16, plus 0/8, 240/4, ::ffff:0:0/96)
     * and additionally rejects the CGNAT 100.64/10 block.
     */
    public static function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true; // not a literal IP → unsafe, block
        }

        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;

        if (! $isPublic) {
            return true;
        }

        // Carrier-grade NAT (100.64.0.0/10) isn't covered by the reserved
        // flags but is non-public for our purposes. Range compare instead of
        // a 0xFFC00000 bitmask: the mask literal overflows to float on 32-bit
        // PHP (deprecation spam on every request + lossy masking), while both
        // range bounds fit a signed 32-bit int on every platform.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($ip);
            if ($long !== false && $long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255')) {
                return true;
            }
        }

        return false;
    }

    /** @return string[] resolved IPs (IPv4 + IPv6); the host itself if it is an IP literal. */
    private static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (! empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
