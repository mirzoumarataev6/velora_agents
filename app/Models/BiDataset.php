<?php

namespace App\Models;

use App\Models\Concerns\ScopesActiveTeam;
use App\Models\Concerns\StampsTeam;
use App\Policies\BiDatasetPolicy;
use App\Services\Bi\BiIngestionException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'sheet_name', 'physical_table', 'external_table', 'row_count',
    'column_count', 'version', 'status', 'error_code', 'error_detail',
    'skipped_rows', 'skipped_reasons', 'truncated', 'shard_directory',
    'shard_count', 'materialized_at',
])]
#[UsePolicy(BiDatasetPolicy::class)]
class BiDataset extends Model
{
    use HasFactory;
    use ScopesActiveTeam;
    use StampsTeam;

    public const STATUS_INFERRING = 'inferring';

    public const STATUS_MATERIALIZING = 'materializing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const TABLE_PATTERN = '/^bi_ds_\d+$/';

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

    public function columns(): HasMany
    {
        return $this->hasMany(BiColumn::class, 'bi_dataset_id')->orderBy('ordinal');
    }

    public function physicalTable(): string
    {
        return self::tableNameFor($this->getKey());
    }

    public static function tableNameFor(int|string|null $id): string
    {
        $name = 'bi_ds_'.$id;

        return self::assertSafeTableName($name);
    }

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

    public function isExternal(): bool
    {
        return $this->external_table !== null && $this->external_table !== '';
    }

    public function shardDirectory(): string
    {
        return $this->shard_directory ?: "bi/{$this->user_id}/datasets/{$this->getKey()}";
    }
}
