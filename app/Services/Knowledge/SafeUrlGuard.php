<?php

namespace App\Services\Knowledge;

use RuntimeException;

/**
 * Guards against SSRF when fetching user-supplied URLs (knowledge sources):
 * rejects non-http(s) schemes and resolves the hostname to reject private/
 * reserved/loopback IP ranges before any request is made.
 */
class SafeUrlGuard
{
    /**
     * Validate that a URL is safe to fetch server-side.
     *
     * @return array{0: string, 1: int} The validated [ip, port] to pin the
     *                                  connection to, preventing DNS-rebinding
     *                                  between this check and the actual fetch.
     *
     * @throws RuntimeException
     */
    public static function assertSafe(string $url): array
    {
        $parts = parse_url($url);

        if (! $parts || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('The URL is not valid.');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Only http and https URLs are allowed.');
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        $ips = self::resolveIps($host);

        if ($ips === []) {
            throw new RuntimeException('The URL host could not be resolved.');
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new RuntimeException('This URL points to a private or reserved network address and cannot be fetched.');
            }
        }

        return [$ips[0], $port];
    }

    protected static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * @return string[]
     */
    protected static function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = array_merge($ips, $ipv4);
        }

        $records = @dns_get_record($host, DNS_AAAA) ?: [];
        foreach ($records as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
