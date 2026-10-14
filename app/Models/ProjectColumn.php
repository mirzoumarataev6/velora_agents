<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectColumn extends Model
{
    protected $fillable = ['team_id', 'board_id', 'name', 'position', 'is_done', 'wip_limit'];

    protected function casts(): array
    {
        return ['is_done' => 'boolean', 'position' => 'integer', 'wip_limit' => 'integer'];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(ProjectBoard::class, 'board_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'project_column_id');
    }
}
