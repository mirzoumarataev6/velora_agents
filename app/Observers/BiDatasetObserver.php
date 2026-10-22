<?php

namespace App\Observers;

use App\Jobs\Bi\DropDatasetTableJob;
use App\Models\BiDataset;

class BiDatasetObserver
{
    public function deleted(BiDataset $dataset): void
    {

        DropDatasetTableJob::dispatch(
            $dataset->physicalTable(),
            $dataset->shardDirectory(),
        )->afterCommit();
    }
}
