<?php

namespace App\Models;

use App\Models\Concerns\ScopesActiveTeam;
use App\Models\Concerns\StampsTeam;
use App\Policies\BiConnectionPolicy;
use App\Services\Database\ConnectionConfig;
use Database\Factories\BiConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The BI module's own live database connection — owned by the account, for
 * analysis, and entirely independent of the agents.
 *
 * THE SEPARATION IS THE POINT, so it is worth stating what this is NOT. It is
 * not `DatabaseConnection`, and it does not reference one. That model is an
 * agent's connection to the shop's product database: one row per agent, owned
 * through the agent, and deleted with it. Pointing BI at those rows meant a
 * business's analytics depended on an assistant it might delete tomorrow, and
 * meant one set of credentials served two features with different lifetimes and
 * different threat models.
 *
 * An owner who wants to analyse the same physical database their agent reads
 * sets it up again here, as its own record with its own credentials. That is a
 * deliberate cost — the same password stored twice — and it buys two systems
 * that can be rotated, revoked and deleted independently. A shared row cannot.
 *
 * WHAT IS REUSED, unchanged, is everything that makes a live connection safe:
 * `SafeHostGuard` still decides which hosts may be dialled, `AbstractPdoDriver`
 * still opens a read-only, time-limited session and re-checks the finished SQL,
 * and the password is still encrypted at rest by the same cast. Only the record
 * moved.
 */
#[Fillable([
    'name', 'engine', 'host', 'port', 'database', 'username', 'password', 'ssl',
])]
#[UsePolicy(BiConnectionPolicy::class)]
class BiConnection extends Model
{
    /** @use HasFactory<BiConnectionFactory> */
    use HasFactory;

    use ScopesActiveTeam;
    use StampsTeam;

    public const STATUS_READY = 'ready';

    public const STATUS_ERROR = 'error';

    public const STATUS_UNVERIFIED = 'unverified';

    /** Connections one account may keep, so a leak cannot become a port scan. */
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

    /**
     * The sources registered from this connection.
     *
     * Needed by the delete path, not by any read: deleting a connection has to
     * remove them THROUGH ELOQUENT so the observer chain runs and the runtime
     * tables and shards go with them. The foreign key is `restrictOnDelete`
     * precisely so that forgetting is an error rather than a silent leak.
     *
     * @return HasMany<BiSource, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(BiSource::class, 'bi_connection_id');
    }

    /**
     * Whether this connection may be used to register tables.
     *
     * Only a verified one. Offering a connection that has never dialled would be
     * offering something we already know does not work — and the datasets it
     * produced would fail on their first query, long after the owner had stopped
     * associating the failure with the credentials they typed.
     */
    public function isUsable(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * The credentials as the driver layer's value object.
     *
     * A `ConnectionConfig` rather than the model itself, so nothing in the
     * driver layer can reach back into the ORM, load another tenant's row, or
     * persist anything — the same reason the agent-side connection does it.
     */
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

    /** What the picker shows: enough to tell two apart, never a credential. */
    public function label(): string
    {
        return $this->name !== '' ? $this->name : $this->database.' · '.$this->host;
    }
}
