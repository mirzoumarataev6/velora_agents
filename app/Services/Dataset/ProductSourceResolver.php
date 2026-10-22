<?php

namespace App\Services\Dataset;

use App\Models\Agent;
use App\Models\DatabaseConnection;
use App\Services\Database\DriverManager;
use App\Services\Database\LiveProductSource;

class ProductSourceResolver
{
    public const DATASET = 'dataset';

    public const DATABASE = 'database';

    public const SOURCES = [self::DATASET, self::DATABASE];

    public function __construct(protected DriverManager $drivers) {}

    public function for(Agent $agent): ?ProductSource
    {
        if ($agent->product_source === self::DATABASE) {
            $connection = DatabaseConnection::query()->where('agent_id', $agent->id)->first();

            if ($connection === null || ! $connection->isUsable()) {
                return null;
            }

            return new LiveProductSource($connection, $this->drivers);
        }

        return new DatasetProductSource($agent);
    }
}
