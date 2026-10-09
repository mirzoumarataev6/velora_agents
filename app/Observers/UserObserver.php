<?php

namespace App\Observers;

use App\Models\User;

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
    public function deleting(User $user): void
    {
        // `get()` rather than `cursor()`, for the reason BiSourceObserver spells
        // out: deleting each one issues DDL, and DDL under an open cursor is a lock.
        $user->biSources()->get()->each(fn ($source) => $source->delete());
    }
}
