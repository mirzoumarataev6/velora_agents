<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectChecklistItem extends Model
{
    protected $fillable = ['team_id', 'board_id', 'task_id', 'title', 'position', 'due_at', 'completed_at'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
