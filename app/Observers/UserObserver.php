<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Teams\CreatesPersonalTeam;

class UserObserver
{
    public function created(User $user): void
    {
        $team = app(CreatesPersonalTeam::class)->create($user);

        $user->forceFill(['current_team_id' => $team->id])->save();
    }

    public function deleting(User $user): void
    {

        $user->biSources()->get()->each(fn ($source) => $source->delete());

        $user->notifications()->delete();
    }
}
