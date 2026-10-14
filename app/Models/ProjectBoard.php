<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectBoard extends Model
{
    protected $fillable = ['team_id', 'created_by', 'name', 'description', 'slug', 'visibility', 'accent'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public function columns(): HasMany
    {
        return $this->hasMany(ProjectColumn::class, 'board_id')->orderBy('position')->orderBy('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'project_board_id');
    }

    public function labels(): HasMany
    {
        return $this->hasMany(ProjectLabel::class, 'board_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_board_members', 'board_id', 'user_id');
    }
}
