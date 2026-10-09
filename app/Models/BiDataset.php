<?php

namespace App\Models;

use App\Policies\BiDatasetPolicy;
use App\Services\Bi\BiIngestionException;
use Database\Factories\BiDatasetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One queryable table: a sheet of a workbook, a CSV, or a table on a live
 * connection.
 *
 * THE TENANT BOUNDARY OF THE WHOLE QUERY LAYER IS THIS MODEL. A compiled SELECT
 * names `physicalTable()` and nothing else, and that string is derived from this
 * row's own primary key — so the only way to reach another owner's rows is to
 * hold another owner's BiDataset, which the policy is there to prevent. No
 * filter, no spec field and no request parameter participates in choosing the
 * table. That is deliberate: a tenant scope expressed as a WHERE clause can be
 * forgotten, and one expressed as the FROM clause cannot.
 */
#[Fillable([
    'name', 'sheet_name', 'physical_table', 'external_table', 'row_count',
    'column_count', 'version', 'status', 'error_code', 'error_detail',
    'skipped_rows', 'skipped_reasons', 'truncated', 'shard_directory',
    'shard_count', 'materialized_at',
])]
#[UsePolicy(BiDatasetPolicy::class)]
class BiDataset extends Model
{
    /** @use HasFactory<BiDatasetFactory> */
    use HasFactory;

    public const STATUS_INFERRING = 'inferring';

    public const STATUS_MATERIALIZING = 'materializing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    /**
     * The one shape a physical analytical table name may have.
     *
     * Enforced on every read AND every write of the name, which is the guard the
     * brief asked for. It cannot fail on user input — the name is built from an
     * integer primary key — so if it ever does fail it has caught a bug in our
     * own code before that bug reached a CREATE or a DROP. That is exactly the
     * failure mode worth an assertion: runtime DDL is the one place in this
     * product where a mistake is not recoverable by editing a row.
     */
    public const TABLE_PATTERN = '/^bi_ds_\d+$/';

    /** The file-order row number every materialized table is keyed by. */
    public const ROW_KEY = '_row';

    protected function casts(): array
    {
        return [
            'row_count' => 'integer',
            'column_count' => 'integer',
            'version' => 'integer',
            'skipped_rows' => 'integer',
            'skipped_reasons' => 'array',
            'truncated' => 'boolean',
            'shard_count' => 'integer',
            'materialized_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(BiSource::class, 'bi_source_id');
    }

    /** @return HasMany<BiColumn, $this> */
    public function columns(): HasMany
    {
        return $this->hasMany(BiColumn::class, 'bi_dataset_id')->orderBy('ordinal');
    }

    /**
     * @param  Builder<BiDataset>  $query
     * @return Builder<BiDataset>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('bi_datasets.user_id', $user->id);
    }

    /**
     * The name this dataset's physical table has, by construction.
     *
     * Derived rather than read back from the column so the two can never drift:
     * the stored value exists for diagnostics and for `DropDatasetTableJob` to
     * find an orphan after the row is gone, but the live DDL and query paths all
     * come through here.
     */
    public function physicalTable(): string
    {
        return self::tableNameFor($this->getKey());
    }

    /**
     * @throws BiIngestionException when the composed name is not the one shape a
     *                              BI table may have — which can only happen if
     *                              the key is missing, i.e. an unsaved model.
     */
    public static function tableNameFor(int|string|null $id): string
    {
        $name = 'bi_ds_'.$id;

        return self::assertSafeTableName($name);
    }

    /**
     * The guard, in the one place every caller must pass through.
     *
     * @throws BiIngestionException
     */
    public static function assertSafeTableName(string $name): string
    {
        if (preg_match(self::TABLE_PATTERN, $name) !== 1) {
            throw new BiIngestionException(
                BiIngestionException::UNSAFE_TABLE_NAME,
                "Refusing to touch [{$name}]: a BI analytical table is always bi_ds_<id>."
            );
        }

        return $name;
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * Whether this dataset reads a table on the customer's own server rather than one
     * of ours.
     *
     * The distinction runs through the whole module: an external dataset has no
     * `bi_ds_*` table, so it is never materialized, never dropped, and never
     * previewed by querying — and its freshness is live rather than a snapshot.
     * Derived from `external_table` rather than from the source's `kind` so it needs
     * no relation loaded to answer.
     */
    public function isExternal(): bool
    {
        return $this->external_table !== null && $this->external_table !== '';
    }

    /**
     * Where this dataset's normalized NDJSON shards live on the local disk.
     *
     * Kept after materialization on purpose. Correcting a wrong type inference
     * then costs one re-materialization from these shards rather than re-reading
     * the original .xlsx — which is the expensive half of ingest and, for a
     * legacy .xls, the half that needs the whole workbook in memory.
     */
    public function shardDirectory(): string
    {
        return $this->shard_directory ?: "bi/{$this->user_id}/datasets/{$this->getKey()}";
    }
}
