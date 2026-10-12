<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\DatabaseConnectionPolicy;
use App\Services\Database\Discovery\DiscoveredSchema;
use App\Services\Database\Discovery\JoinPlan;
use Database\Factories\DatabaseConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One agent's live connection to the business's own product database.
 *
 * The advanced alternative to the uploaded catalog: instead of importing a
 * spreadsheet into `dataset_products`, the agent reads the shop's real table on
 * every question, so prices and stock are never a snapshot.
 *
 * Three things about this row are load-bearing:
 *
 *  - `password` is encrypted at rest and never leaves the server. The tab shows
 *    a masked placeholder and only sends a password when one is retyped.
 *  - `table_name` and `schema_columns` are written by schema discovery ONLY.
 *    Every identifier that reaches the SQL builder is looked up in them first,
 *    which is what makes an injected column name impossible rather than merely
 *    unlikely.
 *  - `status` gates the tool. A connection that has never verified is not
 *    offered to the model at all.
 */
#[Fillable([
    'engine', 'host', 'port', 'database', 'username', 'password', 'ssl',
])]
#[UsePolicy(DatabaseConnectionPolicy::class)]
class DatabaseConnection extends Model
{
    /** @use HasFactory<DatabaseConnectionFactory> */
    use HasFactory;

    use StampsTeam;

    public const STATUS_READY = 'ready';

    public const STATUS_ERROR = 'error';

    public const STATUS_UNVERIFIED = 'unverified';

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'ssl' => 'boolean',
            'port' => 'integer',
            'mapping' => 'array',
            'schema_columns' => 'array',
            'schema_snapshot' => 'array',
            'joins' => 'array',
            'discovery' => 'array',
            'discovered_at' => 'datetime',
            'last_tested_at' => 'datetime',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Whether this connection can actually answer a product question: verified,
     * pointed at a table, and told which column holds the product name.
     *
     * Anything less and the agent must keep using the dataset — a half-configured
     * connection that silently returns nothing would have the agent telling
     * customers the shop sells nothing.
     */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_READY
            && ! empty($this->table_name)
            && ! empty($this->mapping['name'] ?? null);
    }

    /**
     * The full schema as discovery last read it, or null when this connection
     * was only ever set up by hand.
     *
     * This is what a JOINED query validates every identifier against. It is
     * deliberately the stored snapshot rather than a fresh read: the plan below
     * was confirmed against this exact shape, and a schema that has changed
     * underneath us must fail the check rather than quietly compose something
     * the owner never saw.
     */
    public function discoveredSchema(): ?DiscoveredSchema
    {
        $snapshot = $this->schema_snapshot;

        return is_array($snapshot) && $snapshot !== [] ? DiscoveredSchema::fromArray($snapshot) : null;
    }

    /**
     * The confirmed joins, or an empty plan.
     *
     * Empty is the normal case and always safe: the search then reads the
     * product table alone, exactly as it did before joins existed.
     */
    public function joinPlan(): JoinPlan
    {
        return is_array($this->joins) ? JoinPlan::fromArray($this->joins) : new JoinPlan;
    }

    /**
     * The discovered columns as name => ['type' => ..., 'numeric' => bool].
     *
     * @return array<string, array{type: string, numeric: bool}>
     */
    public function columns(): array
    {
        $columns = [];

        foreach ($this->schema_columns ?? [] as $column) {
            if (isset($column['name'])) {
                $columns[$column['name']] = [
                    'type' => (string) ($column['type'] ?? ''),
                    'numeric' => (bool) ($column['numeric'] ?? false),
                ];
            }
        }

        return $columns;
    }
}
