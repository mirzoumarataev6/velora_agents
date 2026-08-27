<?php

namespace App\Services\Dataset;

use App\Models\Agent;
use App\Models\DatabaseConnection;
use App\Services\Database\DriverManager;
use App\Services\Database\LiveProductSource;

/**
 * Which of the two product sources an agent is currently using.
 *
 * The choice is explicit and exclusive — `agents.product_source` — rather than
 * inferred from what happens to be configured. An agent that has both an old
 * uploaded catalog and a live connection must answer from ONE of them, and the
 * owner must be able to see which; a resolver that quietly preferred whichever
 * was set up most recently would have the agent quoting last quarter's prices
 * with no visible reason.
 */
class ProductSourceResolver
{
    public const DATASET = 'dataset';

    public const DATABASE = 'database';

    /** The values `agents.product_source` may hold. */
    public const SOURCES = [self::DATASET, self::DATABASE];

    public function __construct(protected DriverManager $drivers) {}

    /**
     * The active source, or null when the agent has none that can answer.
     *
     * Null is a real answer, not a failure: an owner who switched to a live
     * connection and has not finished configuring it gets an agent with no
     * product tool at all, which is far better than one that reports an empty
     * shop.
     */
    public function for(Agent $agent): ?ProductSource
    {
        if ($agent->product_source === self::DATABASE) {
            // Scoped by agent_id, from the Agent the caller already holds.
            $connection = DatabaseConnection::query()->where('agent_id', $agent->id)->first();

            if ($connection === null || ! $connection->isUsable()) {
                return null;
            }

            return new LiveProductSource($connection, $this->drivers);
        }

        return new DatasetProductSource($agent);
    }
}
