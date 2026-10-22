<?php

namespace App\Services\Bi\Ingestion;

use App\Models\BiColumn;
use App\Models\BiConnection;
use App\Models\BiDataset;
use App\Models\BiSource;
use App\Models\User;
use App\Services\Bi\BiIngestionException;
use App\Services\Bi\Schema\ColumnType;
use App\Services\Database\DatabaseConnectionException;
use App\Services\Database\DatabaseDriver;
use App\Services\Database\DriverManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExternalDatasetImporter
{
    public function __construct(private readonly DriverManager $drivers) {}

    public function availableConnections(User $user): Collection
    {
        return BiConnection::ownedBy($user)
            ->where('status', BiConnection::STATUS_READY)
            ->orderBy('name')
            ->get();
    }

    public function listTables(BiConnection $connection): array
    {
        return $this->guarded(fn () => $this->driver($connection)->listTables());
    }

    public function import(User $user, BiConnection $connection, array $tables, ?string $name = null): BiSource
    {
        $tables = array_values(array_unique(array_filter(array_map('strval', $tables))));

        if ($tables === []) {
            throw new BiIngestionException(
                BiIngestionException::NO_ROWS,
                'No table was chosen to register.'
            );
        }

        $this->assertWithinDatasetQuota($user, count($tables));

        $described = [];

        foreach ($tables as $table) {
            $described[$table] = $this->guarded(fn () => $this->driver($connection)->describeTable($table));
        }

        $source = new BiSource([
            'kind' => BiSource::KIND_CONNECTION,
            'name' => $name !== null && trim($name) !== ''
                ? mb_substr(trim($name), 0, 120)
                : $connection->label(),
            'bi_connection_id' => $connection->getKey(),

            'status' => BiSource::STATUS_READY,
            'normalized_at' => now(),
        ]);

        $source->user_id = $user->id;
        $source->team_id = $connection->team_id;
        $source->save();

        foreach ($described as $table => $schema) {
            $this->createDataset($source, (string) $table, $schema);
        }

        return $source;
    }

    public function refresh(BiDataset $dataset): void
    {
        $connection = $dataset->source?->biConnection;

        if ($connection === null) {
            throw new BiIngestionException(
                BiIngestionException::UNSUPPORTED_FORMAT,
                'This dataset is not backed by a live connection.'
            );
        }

        $schema = $this->guarded(
            fn () => $this->driver($connection)->describeTable((string) $dataset->external_table)
        );

        $this->writeColumns($dataset, $schema);

        $dataset->forceFill([
            'column_count' => count($schema['columns'] ?? []),
            'version' => (int) $dataset->version + 1,
            'status' => BiDataset::STATUS_READY,
            'error_code' => null,
            'error_detail' => null,
            'materialized_at' => now(),
        ])->save();
    }

    protected function createDataset(BiSource $source, string $table, array $schema): BiDataset
    {
        $dataset = new BiDataset([

            'name' => mb_substr($table, 0, 120),
            'external_table' => $table,
            'column_count' => count($schema['columns'] ?? []),

            'row_count' => 0,
            'status' => BiDataset::STATUS_READY,
            'version' => 1,
            'materialized_at' => now(),
        ]);

        $dataset->user_id = $source->user_id;

        $dataset->team_id = $source->team_id;
        $dataset->bi_source_id = $source->getKey();
        $dataset->save();

        $this->writeColumns($dataset, $schema);

        return $dataset;
    }

    protected function writeColumns(BiDataset $dataset, array $schema): void
    {
        $columns = array_values($schema['columns'] ?? []);
        $sample = array_values($schema['sample'] ?? []);
        $now = now();
        $rows = [];

        foreach ($columns as $ordinal => $column) {
            $sourceName = (string) ($column['name'] ?? '');

            if ($sourceName === '') {
                continue;
            }

            $type = $this->mapType((string) ($column['type'] ?? ''), (bool) ($column['numeric'] ?? false));

            $samples = [];
            foreach (array_slice($sample, 0, max(1, (int) config('agent.bi.ingest.sample_values', 5))) as $row) {
                $value = $row[$sourceName] ?? null;

                if ($value !== null && $value !== '') {
                    $samples[] = mb_substr((string) $value, 0, 200);
                }
            }

            $rows[] = [
                'user_id' => $dataset->user_id,

                'team_id' => $dataset->team_id,
                'bi_dataset_id' => $dataset->getKey(),
                'ordinal' => $ordinal,

                'source_name' => mb_substr($sourceName, 0, 480),
                'physical_name' => BiColumn::physicalNameFor($ordinal),
                'label' => null,
                'type' => $type->value,
                'inferred_type' => $type->value,
                'role' => $type->defaultRole()->value,

                'format' => json_encode(['external_type' => (string) ($column['type'] ?? '')], JSON_UNESCAPED_UNICODE),

                'distinct_count' => null,
                'distinct_exact' => false,
                'blank_count' => 0,
                'max_length' => null,
                'sample_values' => json_encode($samples, JSON_UNESCAPED_UNICODE),
                'min_value' => null,
                'max_value' => null,
                'cast_failures' => 0,
                'cast_failure_samples' => null,

                'indexed' => false,
                'description' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            throw new BiIngestionException(
                BiIngestionException::NO_HEADER,
                'The table reported no readable columns.'
            );
        }

        DB::transaction(function () use ($dataset, $rows) {
            BiColumn::where('bi_dataset_id', $dataset->getKey())->delete();

            foreach (array_chunk($rows, 50) as $chunk) {
                BiColumn::insert($chunk);
            }
        });
    }

    protected function mapType(string $engineType, bool $numeric): ColumnType
    {
        $type = strtolower(trim($engineType));

        return match (true) {

            str_contains($type, 'timestamp'), str_contains($type, 'datetime') => ColumnType::DateTime,
            $type === 'date' => ColumnType::Date,
            str_contains($type, 'bool'), $type === 'bit', $type === 'tinyint(1)' => ColumnType::Boolean,
            str_contains($type, 'int') && ! str_contains($type, 'interval') => ColumnType::Integer,
            str_contains($type, 'decimal'), str_contains($type, 'numeric'),
            str_contains($type, 'float'), str_contains($type, 'double'),
            str_contains($type, 'real'), str_contains($type, 'money') => ColumnType::Decimal,

            $numeric => ColumnType::Decimal,
            default => ColumnType::Text,
        };
    }

    protected function driver(BiConnection $connection): DatabaseDriver
    {

        return $this->drivers->make($connection->config());
    }

    protected function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (DatabaseConnectionException $e) {
            throw new BiIngestionException(
                BiIngestionException::CONNECTION_FAILED,

                'The live connection could not be read ('.$e->reason.'): '.$e->getMessage(),
                $e,
            );
        }
    }

    protected function assertWithinDatasetQuota(User $user, int $adding): void
    {
        $max = max(1, (int) config('agent.bi.ingest.max_datasets_per_owner', 25));
        $existing = BiDataset::ownedBy($user)->count();

        if ($existing + $adding > $max) {
            throw new BiIngestionException(
                BiIngestionException::TOO_MANY_DATASETS,
                "Registering {$adding} table(s) would exceed the limit of {$max} datasets."
            );
        }
    }
}
