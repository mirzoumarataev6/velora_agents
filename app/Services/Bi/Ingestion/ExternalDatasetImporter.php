<?php

namespace App\Services\Bi\Ingestion;

use App\Models\BiColumn;
use App\Models\BiConnection;
use App\Models\BiDataset;
use App\Models\BiSource;
use App\Models\User;
use App\Services\Bi\BiIngestionException;
use App\Services\Bi\Schema\ColumnType;
use App\Services\Database\ConnectionConfig;
use App\Services\Database\DatabaseConnectionException;
use App\Services\Database\DatabaseDriver;
use App\Services\Database\DriverManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Registers tables on a live customer database as BI datasets.
 *
 * NOTHING IS COPIED. A connection-backed dataset stores no rows of its own: it
 * records the table's NAME and its column list, and every query reads the customer's
 * server at the time it is asked. That is the whole point of choosing a connection
 * over an upload — the numbers on the dashboard are not a snapshot.
 *
 * Consequences worth stating, because they are what makes this a different thing
 * from a file source rather than a variant of one:
 *
 *  - `physical_table` stays null and `external_table` carries the customer's own
 *    table name. There is no `bi_ds_*` table to create, so no DDL and no
 *    materialization job.
 *  - The schema is a SNAPSHOT of what the connection reported, exactly as
 *    `database_connections.schema_columns` is. A query validates every identifier
 *    against it, so a table that has changed underneath us must fail the check
 *    rather than quietly composing something the owner never saw. Re-registering is
 *    how the snapshot is refreshed.
 *  - Freshness is the customer's server's problem and ours to bound: the existing
 *    driver layer already applies a read-only session, a statement timeout and the
 *    SSRF guard, and all of that is inherited here unchanged.
 *
 * The credentials come from a `BiConnection` — the BI module's own record, owned
 * by the user. NOT from `DatabaseConnection`, which belongs to an agent: that
 * coupling tied the business's analytics to the lifetime of an assistant, so
 * deleting an agent silently left these sources pointing at nothing. See
 * BiConnection's docblock for the full reasoning.
 *
 * What is unchanged is every safety mechanism: the same DriverManager, the same
 * SSRF guard, the same read-only time-limited session, the same encrypted cast.
 * Only whose record it is changed.
 */
class ExternalDatasetImporter
{
    public function __construct(private readonly DriverManager $drivers) {}

    /**
     * Every connection this owner could point the BI module at.
     *
     * Scoped by `user_id` on the row itself — not by walking through the
     * account's agents, which is how this read before the BI module had
     * connections of its own. Only verified ones: a connection that has never
     * successfully dialled would produce a source whose every dataset fails on
     * first query, and offering it would be offering something we already know
     * does not work.
     *
     * @return Collection<int, BiConnection>
     */
    public function availableConnections(User $user): Collection
    {
        return BiConnection::ownedBy($user)
            ->where('status', BiConnection::STATUS_READY)
            ->orderBy('name')
            ->get();
    }

    /**
     * The tables and views this connection can read.
     *
     * @return array<int, array{name: string, type: string}>
     *
     * @throws BiIngestionException
     */
    public function listTables(BiConnection $connection): array
    {
        return $this->guarded(fn () => $this->driver($connection)->listTables());
    }

    /**
     * Register the chosen tables as datasets under a new connection-backed source.
     *
     * @param  array<int, string>  $tables
     *
     * @throws BiIngestionException
     */
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

        // Read the whole schema BEFORE anything is written. A connection that fails
        // half way through would otherwise leave a source with two of its four
        // tables registered and no indication which.
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
            // Ready immediately: there is nothing to normalize and nothing to build.
            'status' => BiSource::STATUS_READY,
            'normalized_at' => now(),
        ]);

        $source->user_id = $user->id;
        $source->save();

        foreach ($described as $table => $schema) {
            $this->createDataset($source, (string) $table, $schema);
        }

        return $source;
    }

    /**
     * Re-read one dataset's columns from the live connection.
     *
     * The refresh path for a table that has gained or lost a column since it was
     * registered. Replaces the column set wholesale, because a stale row describing
     * a column the table no longer has is a row a query could name.
     *
     * @throws BiIngestionException
     */
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

    /**
     * @param  array{columns: array<int, array{name: string, type: string, numeric: bool}>, sample: array<int, array<string, mixed>>}  $schema
     */
    protected function createDataset(BiSource $source, string $table, array $schema): BiDataset
    {
        $dataset = new BiDataset([
            // The customer's own table name, which is what they will look for.
            'name' => mb_substr($table, 0, 120),
            'external_table' => $table,
            'column_count' => count($schema['columns'] ?? []),
            // Live data: there is no row count without a COUNT(*) over the
            // customer's table, and running one per table at registration time is
            // not worth what it costs on a ten-million-row table.
            'row_count' => 0,
            'status' => BiDataset::STATUS_READY,
            'version' => 1,
            'materialized_at' => now(),
        ]);

        $dataset->user_id = $source->user_id;
        $dataset->bi_source_id = $source->getKey();
        $dataset->save();

        $this->writeColumns($dataset, $schema);

        return $dataset;
    }

    /**
     * @param  array{columns: array<int, array{name: string, type: string, numeric: bool}>, sample: array<int, array<string, mixed>>}  $schema
     */
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

            // A few real values, so the schema drawer shows the owner what they are
            // looking at rather than only a type name. Read from the sample the
            // driver already fetched — no extra query.
            $samples = [];
            foreach (array_slice($sample, 0, max(1, (int) config('agent.bi.ingest.sample_values', 5))) as $row) {
                $value = $row[$sourceName] ?? null;

                if ($value !== null && $value !== '') {
                    $samples[] = mb_substr((string) $value, 0, 200);
                }
            }

            $rows[] = [
                'user_id' => $dataset->user_id,
                // Bulk insert bypasses BiColumn's StampsTeam creating hook, so the
                // dataset's own team_id (resolved once, not per row) is stamped
                // here explicitly — see StampsTeam's docblock for why this matters.
                'team_id' => $dataset->team_id,
                'bi_dataset_id' => $dataset->getKey(),
                'ordinal' => $ordinal,
                // The customer's column name is DATA here, exactly as a file's header
                // is: it is stored, shown and put in the AI's context, and it never
                // reaches an identifier position. `physical_name` stays ours.
                'source_name' => mb_substr($sourceName, 0, 480),
                'physical_name' => BiColumn::physicalNameFor($ordinal),
                'label' => null,
                'type' => $type->value,
                'inferred_type' => $type->value,
                'role' => $type->defaultRole()->value,
                // The engine's own declared type, kept for the drawer to show and for
                // a future query to sanity-check against.
                'format' => json_encode(['external_type' => (string) ($column['type'] ?? '')], JSON_UNESCAPED_UNICODE),
                // Unknown, and deliberately not measured: a COUNT(DISTINCT) per
                // column on a live customer table is a scan we have no business
                // running at registration time.
                'distinct_count' => null,
                'distinct_exact' => false,
                'blank_count' => 0,
                'max_length' => null,
                'sample_values' => json_encode($samples, JSON_UNESCAPED_UNICODE),
                'min_value' => null,
                'max_value' => null,
                'cast_failures' => 0,
                'cast_failure_samples' => null,
                // Indexing is the customer's decision on their own table. We neither
                // create nor claim one.
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

    /**
     * The engine's type name, mapped onto our closed set.
     *
     * Deliberately coarse. What the query layer needs to know is whether it may SUM
     * a column and whether it may bucket it by month — not whether the customer's
     * server called it MEDIUMINT or INT4. Anything unrecognized becomes text, which
     * is always safe: a text column can be grouped and counted, just not summed.
     */
    protected function mapType(string $engineType, bool $numeric): ColumnType
    {
        $type = strtolower(trim($engineType));

        return match (true) {
            // Order matters: `timestamp` and `datetime` both contain a clock, while
            // a bare `date` does not, and checking the narrower word first would
            // classify `datetime` as a date and lose every time-of-day.
            str_contains($type, 'timestamp'), str_contains($type, 'datetime') => ColumnType::DateTime,
            $type === 'date' => ColumnType::Date,
            str_contains($type, 'bool'), $type === 'bit', $type === 'tinyint(1)' => ColumnType::Boolean,
            str_contains($type, 'int') && ! str_contains($type, 'interval') => ColumnType::Integer,
            str_contains($type, 'decimal'), str_contains($type, 'numeric'),
            str_contains($type, 'float'), str_contains($type, 'double'),
            str_contains($type, 'real'), str_contains($type, 'money') => ColumnType::Decimal,
            // `numeric` is the driver's own verdict, used as a fallback for an engine
            // type name we do not recognize but it does.
            $numeric => ColumnType::Decimal,
            default => ColumnType::Text,
        };
    }

    protected function driver(BiConnection $connection): DatabaseDriver
    {
        // `BiConnection::config()` rather than `ConnectionConfig::fromModel()`:
        // that helper is typed to the agent's model, and widening it would be a
        // shared seam between two systems this change exists to keep apart.
        return $this->drivers->make($connection->config());
    }

    /**
     * Run driver work, translating a connection failure into the BI module's own
     * classified exception so the UI has one error vocabulary rather than two.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    protected function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (DatabaseConnectionException $e) {
            throw new BiIngestionException(
                BiIngestionException::CONNECTION_FAILED,
                // The driver's own classified reason, carried through so the detail
                // line can still say "auth_failed" rather than "something failed".
                'The live connection could not be read ('.$e->reason.'): '.$e->getMessage(),
                $e,
            );
        }
    }

    protected function assertWithinDatasetQuota(User $user, int $adding): void
    {
        $max = max(1, (int) config('agent.bi.ingest.max_datasets_per_owner', 25));
        $existing = BiDataset::where('user_id', $user->id)->count();

        if ($existing + $adding > $max) {
            throw new BiIngestionException(
                BiIngestionException::TOO_MANY_DATASETS,
                "Registering {$adding} table(s) would exceed the limit of {$max} datasets."
            );
        }
    }
}
