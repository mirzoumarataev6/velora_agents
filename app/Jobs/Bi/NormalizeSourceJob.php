<?php

namespace App\Jobs\Bi;

use App\Models\BiSource;
use App\Services\Bi\Ingestion\SourceNormalizer;
use Illuminate\Support\Facades\Log;
use Throwable;

class NormalizeSourceJob extends BiJob
{
    public function __construct(public BiSource $source)
    {
        parent::__construct();
    }

    public function handle(SourceNormalizer $normalizer): void
    {
        $this->source->forceFill([
            'status' => BiSource::STATUS_NORMALIZING,
            'error_code' => null,
            'error_detail' => null,
        ])->save();

        try {
            $datasets = $normalizer->normalize($this->source);
        } catch (Throwable $e) {
            $this->handleFailure($e);

            return;
        }

        $this->source->forceFill([
            'status' => BiSource::STATUS_READY,
            'normalized_at' => now(),
        ])->save();

        foreach ($datasets as $dataset) {
            MaterializeDatasetJob::dispatch($dataset);
        }
    }

    protected function markFailed(Throwable $e): void
    {
        if (! $this->source->exists) {
            return;
        }

        [$code, $detail] = $this->classify($e);

        Log::warning('BI source normalization failed', [
            'bi_source_id' => $this->source->getKey(),
            'code' => $code,
        ]);

        ($this->source->fresh() ?? $this->source)->forceFill([
            'status' => BiSource::STATUS_FAILED,
            'error_code' => $code,
            'error_detail' => $detail,
        ])->save();
    }
}
