<?php

namespace App\Observers;

use App\Jobs\Bi\DropDatasetTableJob;
use App\Models\BiDataset;

/**
 * Keeps a dataset's physical table and its `bi_datasets` row from outliving each
 * other.
 *
 * A dataset is two things in two places: a row in `bi_datasets`, and a real table
 * called `bi_ds_{id}` holding the owner's actual data. A cascade delete knows only
 * about the first. Without this observer, deleting a dataset — or a source, or an
 * ACCOUNT — would leave that table on disk, still full of their rows, with nothing
 * in the application referencing it: invisible to the owner, invisible to us, and
 * still their data. That is a data-retention problem, not untidiness.
 *
 * The drop is queued rather than immediate so that deleting a source with four
 * sheets does not hold the request open for four DDL statements, and so a failed
 * drop is retried instead of lost.
 *
 * `->afterCommit()`: `DeletesAccount` deletes a user's rows inside one
 * `DB::transaction()`, and this `deleted` event fires inside it too — if
 * the surrounding transaction later rolls back (the final `$user->delete()`
 * fails), this dataset row is restored along with everything else, and a
 * DROP that already ran in the meantime would have destroyed data that
 * still exists. Dispatching without a transaction open (the ordinary path —
 * deleting one source or dataset by itself) is unaffected: queued-job
 * dispatch runs a callback immediately when there is nothing to defer to
 * (`DatabaseTransactionsManager::addCallback()`).
 */
class BiDatasetObserver
{
    public function deleted(BiDataset $dataset): void
    {
        // Composed from the primary key before it is gone, and re-checked inside
        // the job against BiDataset::TABLE_PATTERN before anything is dropped.
        DropDatasetTableJob::dispatch(
            $dataset->physicalTable(),
            $dataset->shardDirectory(),
        )->afterCommit();
    }
}
