<?php

namespace App\Services\Bi\Query;

use Illuminate\Database\ConnectionInterface;

class CompilerFactory
{
    public function for(ConnectionInterface $connection): QueryCompiler
    {
        $driver = method_exists($connection, 'getDriverName')
            ? $connection->getDriverName()
            : 'mysql';

        return match ($driver) {
            'sqlite' => new SqliteQueryCompiler,

            'mysql', 'mariadb' => new MySqlQueryCompiler,

            default => throw new BiQueryException(
                BiQueryException::EXECUTION_FAILED,
                "No BI query compiler for database driver [{$driver}]."
            ),
        };
    }
}
