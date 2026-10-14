<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectLabel extends Model
{
    protected $fillable = ['team_id', 'board_id', 'name', 'color'];
}
