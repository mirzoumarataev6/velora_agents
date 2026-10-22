<?php

namespace App\Services\Database;

use Closure;

class DriverManager
{
    protected array $engines = [];

    public function __construct()
    {
        $this->register(
            'mysql',
            MySqlDriver::DEFAULT_PORT,
            'MySQL / MariaDB',
            fn (ConnectionConfig $config) => new MySqlDriver($config),
        );

        $this->register(
            'pgsql',
            PostgresDriver::DEFAULT_PORT,
            'PostgreSQL',
            fn (ConnectionConfig $config) => new PostgresDriver($config),
        );
    }

    public function register(string $engine, int $defaultPort, string $label, Closure $factory): void
    {
        $this->engines[$engine] = ['factory' => $factory, 'port' => $defaultPort, 'label' => $label];
    }

    public function make(ConnectionConfig $config): DatabaseDriver
    {
        $engine = $this->engines[$config->engine] ?? null;

        if ($engine === null) {
            throw new DatabaseConnectionException(
                DatabaseConnectionException::UNSUPPORTED_ENGINE,
                "No driver registered for engine [{$config->engine}]."
            );
        }

        return ($engine['factory'])($config);
    }

    public function keys(): array
    {
        return array_keys($this->engines);
    }

    public function supports(string $engine): bool
    {
        return isset($this->engines[$engine]);
    }

    public function defaultPort(string $engine): int
    {
        return $this->engines[$engine]['port'] ?? 0;
    }

    public function options(): array
    {
        $options = [];

        foreach ($this->engines as $key => $engine) {
            $options[] = ['key' => $key, 'label' => $engine['label'], 'port' => $engine['port']];
        }

        return $options;
    }
}
