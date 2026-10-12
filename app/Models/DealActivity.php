<?php

namespace App\Models;

use App\Models\Concerns\StampsTeam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// team_id is fillable alongside user_id: both are only ever set by
// DealObserver::record() from trusted values already in scope (the deal
// itself), never from request input — see that method for why team_id is
// passed explicitly rather than left to StampsTeam's own user_id fallback.
#[Fillable(['user_id', 'team_id', 'deal_id', 'type', 'data'])]
class DealActivity extends Model
{
    use StampsTeam;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
