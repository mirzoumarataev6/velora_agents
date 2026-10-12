<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use App\Policies\BiSourcePolicy;
use Database\Factories\BiSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One thing the owner pointed the BI module at: an uploaded file, or a live
 * read-only database connection.
 *
 * A source is not itself queryable — its DATASETS are. The distinction exists
 * because one workbook is genuinely several tables: "Продажи", "Возвраты" and
 * "Справочник" in one .xlsx are three different shapes, and flattening them into
 * one would make every question about either of them wrong.
 *
 * The uploaded file is kept after normalization, not deleted. It is the only
 * copy of what the owner actually gave us, and re-reading it is the fallback if
 * a shard is ever lost.
 */
#[Fillable([
    'kind', 'name', 'original_filename', 'disk_path', 'bytes', 'mime_type',
    'extension', 'bi_connection_id', 'status', 'error_code', 'error_detail',
    'normalized_at',
])]
#[UsePolicy(BiSourcePolicy::class)]
class BiSource extends Model
{
    /** @use HasFactory<BiSourceFactory> */
    use HasFactory;

    use StampsTeam;

    public const KIND_FILE = 'file';

    public const KIND_CONNECTION = 'connection';

    public const STATUS_PENDING = 'pending';

    public const STATUS_NORMALIZING = 'normalizing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'normalized_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The BI module's own connection this source reads, for `kind=connection`.
     *
     * Deliberately NOT a relation to `DatabaseConnection`. That model belongs to
     * an agent and is deleted with it, so a source hung off one broke silently
     * the moment an owner deleted the assistant. See BiConnection.
     */
    public function biConnection(): BelongsTo
    {
        return $this->belongsTo(BiConnection::class, 'bi_connection_id');
    }

    public function datasets(): HasMany
    {
        return $this->hasMany(BiDataset::class);
    }

    /**
     * Only this owner's sources. Every BI read starts here, so a forgotten where
     * clause cannot show one account another's uploads.
     *
     * @param  Builder<BiSource>  $query
     * @return Builder<BiSource>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('bi_sources.user_id', $user->id);
    }

    /**
     * Where this source's uploaded file and normalized shards live, under the
     * owner's own directory on the local disk.
     *
     * Both segments are integers from our own primary keys, so there is no path
     * traversal to defend against here — and an owner's directory cannot be
     * named by a request at all.
     */
    public function storageDirectory(): string
    {
        return "bi/{$this->user_id}/sources/{$this->id}";
    }

    public function isFile(): bool
    {
        return $this->kind === self::KIND_FILE;
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }
}
