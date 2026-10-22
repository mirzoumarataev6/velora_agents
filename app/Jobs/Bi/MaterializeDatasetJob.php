<?php

namespace App\Jobs\Bi;

use App\Models\BiDataset;
use App\Services\Bi\Ingestion\DatasetMaterializer;
use Illuminate\Support\Facades\Log;
use Throwable;

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
