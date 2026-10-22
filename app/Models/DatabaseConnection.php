<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\DatabaseConnectionPolicy;
use App\Services\Database\Discovery\DiscoveredSchema;
use App\Services\Database\Discovery\JoinPlan;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'engine', 'host', 'port', 'database', 'username', 'password', 'ssl',
])]
#[UsePolicy(DatabaseConnectionPolicy::class)]
class DatabaseConnection extends Model
{
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

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_READY
            && ! empty($this->table_name)
            && ! empty($this->mapping['name'] ?? null);
    }

    public function discoveredSchema(): ?DiscoveredSchema
    {
        $snapshot = $this->schema_snapshot;

        return is_array($snapshot) && $snapshot !== [] ? DiscoveredSchema::fromArray($snapshot) : null;
    }

    public function joinPlan(): JoinPlan
    {
        return is_array($this->joins) ? JoinPlan::fromArray($this->joins) : new JoinPlan;
    }

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
