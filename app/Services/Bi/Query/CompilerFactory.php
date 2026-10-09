<?php

namespace App\Services\Bi\Query;

use Illuminate\Database\ConnectionInterface;

/**
 * Picks the compiler for the connection actually in use.
 *
 * Driven by the live connection's driver name rather than by config, which is
 * the whole point: `phpunit.xml` pins the suite to sqlite and production runs
 * MySQL, and neither should have to remember to also set a "which compiler"
 * flag. A mismatch between the two would not fail loudly — it would emit
 * `DATE_FORMAT` at SQLite and return nulls for every date bucket.
 */
class CompilerFactory
{
    public function for(ConnectionInterface $connection): QueryCompiler
    {
        $driver = method_exists($connection, 'getDriverName')
            ? $connection->getDriverName()
            : 'mysql';

        return match ($driver) {
            'sqlite' => new SqliteQueryCompiler,

            // MariaDB shares MySQL's dialect for everything this compiler emits.
            // The one place they differ is the optimizer hint, which MariaDB
            // ignores as a comment — handled and explained in that class.
            'mysql', 'mariadb' => new MySqlQueryCompiler,

            // Postgres is reachable as a LIVE connection (PostgresDriver in the
            // database layer) but never hosts our analytical tables — those are
            // created by `DatasetMaterializer` on the application's own
            // connection. Refused rather than approximated: silently compiling
            // MySQL syntax for an engine that will reject `DATE_FORMAT` is a
            // worse failure than this one.
            default => throw new BiQueryException(
                BiQueryException::EXECUTION_FAILED,
                "No BI query compiler for database driver [{$driver}]."
            ),
        };
    }
}
