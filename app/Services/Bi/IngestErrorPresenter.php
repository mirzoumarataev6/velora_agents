<?php

namespace App\Services\Bi;

use App\Models\BiDataset;
use App\Models\BiSource;

/**
 * Turns a stored `error_code` into the sentence the owner reads.
 *
 * Its own class because THREE places need the same answer and must not disagree:
 * the server-rendered source list, the JSON the poller updates cards from, and any
 * later surface that shows a failed dataset. A code rendered as different words in
 * two of those is a support conversation about which one is true.
 *
 * The `:limit` placeholder is resolved here rather than stored on the row, because
 * the limit is configuration: an install that raises `max_columns` should say the
 * new number in the message it shows for an old failure, not the number that was in
 * force when the failure happened.
 *
 * An unknown code falls back to the generic internal-error sentence. That can only
 * happen for a code added without its translation — and a correct generic sentence
 * is a much better outcome than `too_many_widgets` appearing in the UI.
 */
class IngestErrorPresenter
{
    /**
     * @param  string|null  $extension  The source's file extension, for the
     *                                  `:format` placeholder. Passed in rather
     *                                  than read off `$model->source` so
     *                                  presenting a list of datasets cannot turn
     *                                  into one query per row.
     */
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
            // Told to the owner as the format we TRIED to open it as, which is
            // what makes "a CSV renamed to .xlsx" a diagnosable message rather
            // than a mystery.
            'format' => strtoupper((string) ($model instanceof BiSource ? $model->extension : $extension)),
        ]);

        return $sentence === $key ? __('bi.errors.internal_error') : $sentence;
    }

    /** The technical remainder shown under the sentence, if there is one. */
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

    /** The budget a given code is about, for its `:limit` placeholder. */
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
