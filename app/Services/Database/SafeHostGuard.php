<?php

namespace App\Services\Database;

/**
 * SSRF guard for the "connect to a customer's database" feature.
 *
 * Connecting to an arbitrary host:port from our server is exactly the shape of
 * an SSRF primitive: an owner could point a connection at 127.0.0.1:3306 and be
 * handed OUR database, or walk 10.0.0.0/8 and use the connection-test button's
 * error messages as a port scanner. So the host is resolved and every address
 * it resolves to must be public.
 *
 * Resolving here and pinning the connection to the resolved IP would also close
 * DNS rebinding, but neither PDO driver lets us dial an IP while still sending
 * the original hostname for TLS — so this checks every A/AAAA record and accepts
 * the (small, documented) rebinding window rather than pretending to close it.
 * SafeUrlGuard does the same job for knowledge-source URLs.
 *
 * `agent.database.allow_private_hosts` opens the gate for self-hosted installs
 * where the customer database genuinely sits on the same private network.
 */
class SafeHostGuard
{
    /**
     * @throws DatabaseConnectionException when the host must not be dialled.
     */
    public static function assertDialable(string $host): void
    {
        if (config('agent.database.allow_private_hosts')) {
            return;
        }

        $host = trim($host);

        if ($host === '') {
            throw new DatabaseConnectionException(DatabaseConnectionException::BLOCKED_HOST, 'Empty host.');
        }

        // Bracketed IPv6 literals ("[::1]") reach us from the port-splitting
        // conventions owners are used to; unwrap before validating.
        $host = trim($host, '[]');

        $ips = self::resolve($host);

        if ($ips === []) {
            throw new DatabaseConnectionException(
                DatabaseConnectionException::UNREACHABLE,
                "Host [{$host}] did not resolve."
            );
        }

        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                throw new DatabaseConnectionException(
                    DatabaseConnectionException::BLOCKED_HOST,
                    "Host [{$host}] resolves to the private or reserved address [{$ip}]."
                );
            }
        }
    }

    protected static function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * @return string[]
     */
    protected static function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $ips = array_merge($ips, $ipv4);
        }

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
