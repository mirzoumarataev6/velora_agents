<?php

namespace App\Services\Database;

use Closure;

/**
 * The registry of supported engines.
 *
 * This is the whole extension point: a new engine is a class implementing
 * DatabaseDriver plus one register() call. Nothing in the controller, the
 * search source or the tool knows which engines exist — they ask here.
 *
 * Two are shipped, MySQL/MariaDB and PostgreSQL, because those are the two we
 * can actually run against. Registering an engine we cannot test would put a
 * choice in the UI that fails on the customer's first real table.
 */
class DriverManager
{
    /** @var array<string, array{factory: Closure, port: int, label: string}> */
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

    /**
     * @param  Closure(ConnectionConfig): DatabaseDriver  $factory
     */
    public function register(string $engine, int $defaultPort, string $label, Closure $factory): void
    {
        $this->engines[$engine] = ['factory' => $factory, 'port' => $defaultPort, 'label' => $label];
    }

    /**
     * @throws DatabaseConnectionException when no driver is registered for the engine.
     */
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

    /** @return string[] */
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

    /**
     * The engine list the setup form renders, with each engine's default port.
     *
     * @return array<int, array{key: string, label: string, port: int}>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->engines as $key => $engine) {
            $options[] = ['key' => $key, 'label' => $engine['label'], 'port' => $engine['port']];
        }

        return $options;
    }
}
