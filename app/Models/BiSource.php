<?php

namespace App\Models;

use App\Models\Concerns\ScopesActiveTeam;
use App\Models\Concerns\StampsTeam;
use App\Policies\BiSourcePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'kind', 'name', 'original_filename', 'disk_path', 'bytes', 'mime_type',
    'extension', 'bi_connection_id', 'status', 'error_code', 'error_detail',
    'normalized_at',
])]
#[UsePolicy(BiSourcePolicy::class)]
class BiSource extends Model
{
    use HasFactory;
    use ScopesActiveTeam;
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

    public function biConnection(): BelongsTo
    {
        return $this->belongsTo(BiConnection::class, 'bi_connection_id');
    }

    public function datasets(): HasMany
    {
        return $this->hasMany(BiDataset::class);
    }

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
