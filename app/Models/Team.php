<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Owns every tenant resource (agents, CRM, BI, ...). `created_by` is audit
 * only — it records who created the row, not who is authorized to act on
 * it; that is `team_user`'s job, introduced alongside this model but not
 * yet read by anything (Stage 1 is schema-only; a later stage wires
 * Policies to it).
 */
#[Fillable(['name', 'slug', 'created_by'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['trial_ends_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = static::uniqueSlug($team->name);
            }
        });
    }

    protected static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: Str::random(8);
        $slug = $base;
        $i = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TeamMembership::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }
}
