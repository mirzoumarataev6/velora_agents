<?php

namespace App\Observers;

use App\Models\BiSource;
use Illuminate\Support\Facades\Storage;

/**
 * Makes deleting a source delete everything a source actually consists of.
 *
 * A source is three things in three places: a row, a staged copy of the owner's
 * uploaded file, and — through its datasets — one physical table each. A SQL cascade
 * knows only about the first.
 *
 * `deleting` walks the datasets through Eloquent. Without it, `bi_datasets.bi_source_id`
 * cascades in the database, which removes the ROWS and fires no Eloquent events, so
 * BiDatasetObserver never runs and every `bi_ds_*` table survives the dataset it
 * belonged to — still holding the owner's rows, with nothing referencing it.
 *
 * `deleted` removes the staged upload. This lives HERE rather than in the controller
 * for a reason worth recording: it used to be in SourceController::destroy, which
 * meant the file was only ever cleaned up when a source was deleted through the UI.
 * Deleting an ACCOUNT reached the tables and left every uploaded file behind — the
 * one outcome "delete my account" must never produce.
 */
class BiSourceObserver
{
    public function deleting(BiSource $source): void
    {
        // One at a time rather than a mass delete, precisely because each one must
        // fire its own `deleted` event and queue its own table drop.
        //
        // `get()`, NOT `cursor()`: a cursor holds its statement open while the loop
        // runs, and the drop it triggers is DDL — which SQLite refuses outright
        // ("database table is locked") and which no engine enjoys. The collection is
        // at most one sheet budget long, so there is nothing to stream.
        $source->datasets()->get()->each(fn ($dataset) => $dataset->delete());
    }

    public function deleted(BiSource $source): void
    {
        $directory = $source->storageDirectory();

        // Both halves of the path are integers from our own primary keys, so this
        // cannot be pointed anywhere else — but the prefix is checked anyway, since
        // the argument to a recursive delete is exactly the kind of thing worth
        // bounding regardless of how it was built.
        if (str_starts_with($directory, 'bi/')) {
            Storage::disk('local')->deleteDirectory($directory);
        }
    }
}
