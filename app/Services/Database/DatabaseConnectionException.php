<?php

namespace App\Services\Database;

use RuntimeException;
use Throwable;

class DatabaseConnectionException extends RuntimeException
{
    public const UNREACHABLE = 'unreachable';

    public const AUTH_FAILED = 'auth_failed';

    public const UNKNOWN_DATABASE = 'unknown_database';

    public const PERMISSION_DENIED = 'permission_denied';

    public const TIMEOUT = 'timeout';

    public const SSL_FAILED = 'ssl_failed';

    public const BLOCKED_HOST = 'blocked_host';

    public const UNSUPPORTED_ENGINE = 'unsupported_engine';

    public const SCHEMA_CHANGED = 'schema_changed';

    public const SCHEMA_TOO_LARGE = 'schema_too_large';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $reason,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }

    public static function fromDriverFailure(Throwable $e): self
    {
        $message = $e->getMessage();
        $sqlState = self::sqlState($e);
        $code = self::driverCode($e, $message);

        $reason = match (true) {

            $code === 1045, $sqlState === '28000', $sqlState === '28P01' => self::AUTH_FAILED,
            $code === 1044, $sqlState === '42501' => self::PERMISSION_DENIED,

            $code === 1049, $sqlState === '3D000' => self::UNKNOWN_DATABASE,

            in_array($code, [2002, 2003, 2005], true),
            in_array($sqlState, ['08001', '08004', '08006', 'HY000'], true) && self::looksUnreachable($message) => self::UNREACHABLE,

            $code === 3024, $sqlState === '57014' => self::TIMEOUT,
            str_contains(strtolower($message), 'ssl') => self::SSL_FAILED,
            self::looksUnreachable($message) => self::UNREACHABLE,
            str_contains(strtolower($message), 'timed out'), str_contains(strtolower($message), 'timeout') => self::TIMEOUT,
            str_contains(strtolower($message), 'access denied'), str_contains(strtolower($message), 'authentication failed') => self::AUTH_FAILED,
            str_contains(strtolower($message), 'unknown database'), str_contains(strtolower($message), 'does not exist') => self::UNKNOWN_DATABASE,
            default => self::UNKNOWN,
        };

        return new self($reason, $message, $e);
    }

    protected static function looksUnreachable(string $message): bool
    {
        $message = strtolower($message);

        foreach ([
            'connection refused', "can't connect", 'could not connect', 'no such host',
            'name or service not known', 'unknown server host', 'host is unreachable',
            'no route to host', 'network is unreachable', 'connection timed out',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    protected static function sqlState(Throwable $e): ?string
    {

        if ($e instanceof \PDOException && isset($e->errorInfo[0])) {
            return (string) $e->errorInfo[0];
        }

        $code = (string) $e->getCode();

        return preg_match('/^[0-9A-Z]{5}$/', $code) ? $code : null;
    }

    protected static function driverCode(Throwable $e, string $message): ?int
    {
        if ($e instanceof \PDOException && isset($e->errorInfo[1]) && is_numeric($e->errorInfo[1])) {
            return (int) $e->errorInfo[1];
        }

        return preg_match('/\[(\d{4})\]/', $message, $matches) ? (int) $matches[1] : null;
    }
}
