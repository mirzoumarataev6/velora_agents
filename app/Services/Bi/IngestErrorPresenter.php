<?php

namespace App\Services\Bi;

use App\Models\BiDataset;
use App\Models\BiSource;

class IngestErrorPresenter
{
    public function sentence(BiSource|BiDataset $model, ?string $extension = null): ?string
    {
        $code = (string) $model->error_code;

        if ($code === '') {
            return null;
        }

        $key = 'bi.errors.'.$code;

        $sentence = __($key, [
            'size' => $this->megabytesFor($code),
            'limit' => $this->limitFor($code),

            'format' => strtoupper((string) ($model instanceof BiSource ? $model->extension : $extension)),
        ]);

        return $sentence === $key ? __('bi.errors.internal_error') : $sentence;
    }

    public function detail(BiSource|BiDataset $model): ?string
    {
        $detail = trim((string) $model->error_detail);

        return $detail === '' ? null : $detail;
    }

    private function megabytesFor(string $code): int
    {
        $kilobytes = $code === BiIngestionException::LEGACY_XLS_TOO_LARGE
            ? (int) config('agent.bi.ingest.max_legacy_xls_size_kb')
            : (int) config('agent.bi.ingest.max_file_size_kb');

        return (int) round($kilobytes / 1024);
    }

    private function limitFor(string $code): int
    {
        return match ($code) {
            BiIngestionException::TOO_MANY_COLUMNS => (int) config('agent.bi.ingest.max_columns'),
            BiIngestionException::TOO_MANY_SHEETS => (int) config('agent.bi.ingest.max_sheets'),
            BiIngestionException::TOO_MANY_DATASETS => (int) config('agent.bi.ingest.max_datasets_per_owner'),
            default => (int) config('agent.bi.ingest.max_rows'),
        };
    }
}
