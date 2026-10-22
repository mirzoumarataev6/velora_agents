<?php

namespace App\Services\Database;

class SafeHostGuard
{
    public static function assertDialable(string $host): void
    {
        if (config('agent.database.allow_private_hosts')) {
            return;
        }

        $host = trim($host);

        if ($host === '') {
            throw new DatabaseConnectionException(DatabaseConnectionException::BLOCKED_HOST, 'Empty host.');
        }

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
