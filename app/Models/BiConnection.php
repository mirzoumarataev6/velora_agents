<?php

namespace App\Models;

use App\Models\Concerns\ScopesActiveTeam;
use App\Models\Concerns\StampsTeam;
use App\Policies\BiConnectionPolicy;
use App\Services\Database\ConnectionConfig;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'engine', 'host', 'port', 'database', 'username', 'password', 'ssl',
])]
#[UsePolicy(BiConnectionPolicy::class)]
class BiConnection extends Model
{
    use HasFactory;
    use ScopesActiveTeam;
    use StampsTeam;

    public const STATUS_READY = 'ready';

    public const STATUS_ERROR = 'error';

    public const STATUS_UNVERIFIED = 'unverified';

    public const MAX_PER_OWNER = 10;

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'ssl' => 'boolean',
            'port' => 'integer',
            'last_tested_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(BiSource::class, 'bi_connection_id');
    }

    public function isUsable(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function config(): ConnectionConfig
    {
        return new ConnectionConfig(
            engine: (string) $this->engine,
            host: (string) $this->host,
            port: (int) $this->port,
            database: (string) $this->database,
            username: (string) $this->username,
            password: (string) ($this->password ?? ''),
            ssl: (bool) $this->ssl,
        );
    }

    public function label(): string
    {
        return $this->name !== '' ? $this->name : $this->database.' · '.$this->host;
    }
}
