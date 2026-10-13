<?php

namespace App\Models;

use App\Models\Concerns\ScopesActiveTeam;
use App\Models\Concerns\StampsTeam;
use App\Policies\BiChatMessagePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['role', 'content', 'payload', 'undone_at'])]
#[UsePolicy(BiChatMessagePolicy::class)]
class BiChatMessage extends Model
{
    use ScopesActiveTeam;
    use StampsTeam;

    protected function casts(): array
    {
        return ['payload' => 'array', 'undone_at' => 'datetime'];
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(BiDashboard::class, 'bi_dashboard_id');
    }
}
