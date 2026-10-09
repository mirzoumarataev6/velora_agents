<?php

namespace App\Jobs\Bi;

use App\Models\BiDataset;
use App\Services\Bi\Ingestion\DatasetMaterializer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Cleanup after a dataset is deleted: drop its physical table and its shards.
 *
 * It exists because a `bi_datasets` row and its analytical table are two different
 * things in two different places, and a cascade delete only knows about the first.
 * Without this, deleting a dataset would leave a `bi_ds_*` table holding the
 * owner's data with nothing in the application referencing it — invisible, still
 * on disk, and still their data.
 *
 * Takes the table NAME and the shard directory rather than the model, because by
 * the time it runs the model is gone. The name is re-checked against
 * BiDataset::TABLE_PATTERN before anything is dropped: this is the one job in the
 * product that issues a DROP, and it will only ever drop something shaped exactly
 * like a BI analytical table.
 */
class DropDatasetTableJob extends BiJob
{
    public function __construct(
        public string $table,
        public ?string $shardDirectory = null,
    ) {
        parent::__construct();
    }

    public function handle(DatasetMaterializer $materializer): void
    {
        try {
            // Throws unless the name matches /^bi_ds_\d+$/, so a malformed or
            // tampered payload drops nothing.
            BiDataset::assertSafeTableName($this->table);

            $materializer->dropTable($this->table);

            if ($this->shardDirectory !== null && str_starts_with($this->shardDirectory, 'bi/')) {
                Storage::disk('local')->deleteDirectory($this->shardDirectory);
            }
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }
    }

    /**
     * There is no row left to write a status onto — the dataset is already gone.
     * A failure here is an orphaned table, which is an operational problem rather
     * than something to tell the owner about, so it is logged loudly instead.
     */
    protected function markFailed(Throwable $e): void
    {
        Log::error('BI dataset table cleanup failed — an orphaned analytical table may remain', [
            'table' => $this->table,
            'error' => $e->getMessage(),
        ]);
    }
}
