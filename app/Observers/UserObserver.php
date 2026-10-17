<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Teams\CreatesPersonalTeam;

/**
 * Account deletion, for the things a SQL cascade cannot reach.
 *
 * `$user->delete()` in SettingsController relies on cascading foreign keys, which
 * is correct for rows and blind to everything else. The BI module stores each
 * dataset as a real `bi_ds_*` table and a directory of normalized shards, so a
 * cascade would delete the bookkeeping and leave the deleted account's actual
 * data on disk — the one outcome "delete my account" must never produce.
 *
 * Deleting the SOURCES through Eloquent starts the chain that handles it:
 * BiSourceObserver deletes their datasets, BiDatasetObserver queues each table's
 * drop and removes its shards. Every dataset belongs to a source, so the sources
 * are a complete entry point.
 */
class UserObserver
{
    /**
     * Every new account gets a personal team the instant it exists, the same
     * shape `team:migrate-users` gave old accounts — see CreatesPersonalTeam's
     * docblock for why the two share this one action. Nothing reads
     * `current_team_id` for authorization yet; this just stops new accounts
     * from being born into the "legacy, no team" state the backfill command
     * exists to repair.
     *
     * A test that needs a genuinely team-less user (to exercise the backfill
     * itself) creates it with `User::factory()->createQuietly(...)`, which
     * skips Eloquent events entirely, this one included.
     */
    public function created(User $user): void
    {
        $team = app(CreatesPersonalTeam::class)->create($user);

        $user->forceFill(['current_team_id' => $team->id])->save();
    }

    public function deleting(User $user): void
    {
        // `get()` rather than `cursor()`, for the reason BiSourceObserver spells
        // out: deleting each one issues DDL, and DDL under an open cursor is a lock.
        $user->biSources()->get()->each(fn ($source) => $source->delete());

        // `notifications` (Notifiable's own table) carries no FK — it is keyed
        // by a polymorphic notifiable_type/notifiable_id pair, so nothing stops
        // a deleted user's rows from sitting there forever pointing at an id
        // that no longer resolves to anyone. Same category of gap as the BI
        // module's physical tables above: a cascade cannot reach it, so this
        // observer is what does.
        $user->notifications()->delete();
    }
}
