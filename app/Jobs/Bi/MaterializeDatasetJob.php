<?php

namespace App\Jobs\Bi;

use App\Models\BiDataset;
use App\Services\Bi\Ingestion\DatasetMaterializer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step two: build the physical analytical table and fill it from the shards.
 *
 * Separate from normalization because the two have opposite retry properties.
 * Normalization is pure reading and can be retried freely; this issues DDL and
 * writes rows, so it is written to be IDEMPOTENT instead — it drops and rebuilds,
 * which makes a retry after a killed worker correct rather than additive.
 *
 * This is also the job an owner re-runs when they correct a wrong type inference,
 * which is why it reads the shards rather than the original file: the correction
 * costs seconds instead of a second full spreadsheet parse.
 */
class MaterializeDatasetJob extends BiJob
{
    public function __construct(public BiDataset $dataset)
    {
        parent::__construct();
    }

    public function handle(DatasetMaterializer $materializer): void
    {
        try {
            $materializer->materialize($this->dataset);
        } catch (Throwable $e) {
            $this->handleFailure($e);
        }
    }

    protected function markFailed(Throwable $e): void
    {
        if (! $this->dataset->exists) {
            return;
        }

        [$code, $detail] = $this->classify($e);

        Log::warning('BI dataset materialization failed', [
            'bi_dataset_id' => $this->dataset->getKey(),
            'code' => $code,
        ]);

        ($this->dataset->fresh() ?? $this->dataset)->forceFill([
            'status' => BiDataset::STATUS_FAILED,
            'error_code' => $code,
            'error_detail' => $detail,
        ])->save();
    }
}
