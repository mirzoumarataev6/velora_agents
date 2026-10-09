<?php

namespace App\Jobs\Bi;

use App\Models\BiSource;
use App\Services\Bi\Ingestion\SourceNormalizer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step one: read the upload once, write the normalized shards, infer the schema.
 *
 * Queued rather than done in the request for the reason the brief insisted on: a
 * 100MB spreadsheet takes minutes, and a request that holds the browser for
 * minutes is a request that times out behind a proxy and leaves the owner with no
 * idea whether their file arrived.
 *
 * Dispatches one MaterializeDatasetJob per sheet on success, so a four-sheet
 * workbook builds its four tables as four independently retryable units of work
 * instead of one job that has to be all-or-nothing.
 */
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

        // fresh() because the row may have been touched since this job was
        // serialized; forceFill so the status transition does not depend on the
        // model's fillable list.
        ($this->source->fresh() ?? $this->source)->forceFill([
            'status' => BiSource::STATUS_FAILED,
            'error_code' => $code,
            'error_detail' => $detail,
        ])->save();
    }
}
