<?php

namespace App\Jobs\Bi;

use App\Models\BiDataset;
use App\Services\Bi\Ingestion\DatasetMaterializer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

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

            BiDataset::assertSafeTableName($this->table);

            $materializer->dropTable($this->table);

            if ($this->shardDirectory !== null && str_starts_with($this->shardDirectory, 'bi/')) {
                Storage::disk('local')->deleteDirectory($this->shardDirectory);
            }
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }
    }

    protected function markFailed(Throwable $e): void
    {
        Log::error('BI dataset table cleanup failed — an orphaned analytical table may remain', [
            'table' => $this->table,
            'error' => $e->getMessage(),
        ]);
    }
}
