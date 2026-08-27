<?php

namespace App\Services\Database;

use RuntimeException;
use Throwable;

/**
 * A connection or query problem, reduced to one of a small set of REASON codes.
 *
 * The reason is what the owner is shown (translated by the controller); the
 * original driver message is kept only for the log. That split is deliberate:
 * a PDO error carries the DSN, the username and sometimes the server's own
 * banner, and none of that belongs in a browser — "wrong password" is both
 * safer and more useful than a stack trace.
 */
class DatabaseConnectionException extends RuntimeException
{
    /** The host:port could not be reached at all (DNS, firewall, wrong port). */
    public const UNREACHABLE = 'unreachable';

    /** Reached the server; it refused these credentials. */
    public const AUTH_FAILED = 'auth_failed';

    /** Authenticated, but the named database does not exist. */
    public const UNKNOWN_DATABASE = 'unknown_database';

    /** Authenticated, but the user may not read what was asked for. */
    public const PERMISSION_DENIED = 'permission_denied';

    /** The server took longer than the configured budget. */
    public const TIMEOUT = 'timeout';

    /** TLS was requested and could not be negotiated. */
    public const SSL_FAILED = 'ssl_failed';

    /** The host is inside a private/reserved range we refuse to dial. */
    public const BLOCKED_HOST = 'blocked_host';

    /** No driver is registered for the requested engine. */
    public const UNSUPPORTED_ENGINE = 'unsupported_engine';

    /** The chosen table or column is gone since discovery ran. */
    public const SCHEMA_CHANGED = 'schema_changed';

    /**
     * The database has more tables than automatic discovery will read.
     *
     * Not a failure of the connection — the manual table picker still works, and
     * the owner is told to use it. Reading every column of a several-thousand
     * table schema to rank candidates is a cost with no matching benefit.
     */
    public const SCHEMA_TOO_LARGE = 'schema_too_large';

    /** Anything the mapping below did not recognize. */
    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $reason,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }

    /**
     * Classify a driver-level failure into a reason the owner can act on.
     *
     * Matched on SQLSTATE and the engine's own error code first, and only then
     * on message text — text is locale- and version-dependent, so it is the
     * fallback rather than the rule.
     */
    public static function fromDriverFailure(Throwable $e): self
    {
        $message = $e->getMessage();
        $sqlState = self::sqlState($e);
        $code = self::driverCode($e, $message);

        $reason = match (true) {
            // MySQL: 1045 access denied for user, 1044 access denied to database.
            $code === 1045, $sqlState === '28000', $sqlState === '28P01' => self::AUTH_FAILED,
            $code === 1044, $sqlState === '42501' => self::PERMISSION_DENIED,
            // MySQL 1049 unknown database, Postgres 3D000 invalid catalog name.
            $code === 1049, $sqlState === '3D000' => self::UNKNOWN_DATABASE,
            // MySQL 2002/2003/2005, Postgres 08001/08006 connection failure.
            in_array($code, [2002, 2003, 2005], true),
            in_array($sqlState, ['08001', '08004', '08006', 'HY000'], true) && self::looksUnreachable($message) => self::UNREACHABLE,
            // MySQL 3024 / Postgres 57014 — the statement timeout fired.
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
        // PDOException puts SQLSTATE in ->getCode(), but only sometimes — the
        // errorInfo array is the reliable place when it is present.
        if ($e instanceof \PDOException && isset($e->errorInfo[0])) {
            return (string) $e->errorInfo[0];
        }

        $code = (string) $e->getCode();

        return preg_match('/^[0-9A-Z]{5}$/', $code) ? $code : null;
    }

    /**
     * The engine's own numeric error code — errorInfo[1] when PDO exposes it,
     * otherwise parsed out of the message PDO formats as
     * "SQLSTATE[HY000] [1045] Access denied ...".
     */
    protected static function driverCode(Throwable $e, string $message): ?int
    {
        if ($e instanceof \PDOException && isset($e->errorInfo[1]) && is_numeric($e->errorInfo[1])) {
            return (int) $e->errorInfo[1];
        }

        return preg_match('/\[(\d{4})\]/', $message, $matches) ? (int) $matches[1] : null;
    }
}
