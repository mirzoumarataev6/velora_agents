<?php

namespace App\Services\Database;

use App\Models\DatabaseConnection;

final class ConnectionConfig
{
    public function __construct(
        public readonly string $engine,
        public readonly string $host,
        public readonly int $port,
        public readonly string $database,
        public readonly string $username,
        public readonly string $password = '',
        public readonly bool $ssl = false,
    ) {}

    public static function fromModel(DatabaseConnection $connection): self
    {
        return new self(
            engine: (string) $connection->engine,
            host: (string) $connection->host,
            port: (int) $connection->port,
            database: (string) $connection->database,
            username: (string) $connection->username,
            password: (string) ($connection->password ?? ''),
            ssl: (bool) $connection->ssl,
        );
    }

    public static function fromArray(array $attributes): self
    {
        return new self(
            engine: (string) ($attributes['engine'] ?? ''),
            host: (string) ($attributes['host'] ?? ''),
            port: (int) ($attributes['port'] ?? 0),
            database: (string) ($attributes['database'] ?? ''),
            username: (string) ($attributes['username'] ?? ''),
            password: (string) ($attributes['password'] ?? ''),
            ssl: (bool) ($attributes['ssl'] ?? false),
        );
    }

    public function withPassword(string $password): self
    {
        return new self($this->engine, $this->host, $this->port, $this->database, $this->username, $password, $this->ssl);
    }

    public function forLog(): array
    {
        return [
            'engine' => $this->engine,
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'ssl' => $this->ssl,
        ];
    }
}
