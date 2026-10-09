<?php

namespace App\Http\Requests\Bi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

/**
 * Validates a BI file upload BEFORE anything is written or queued.
 *
 * The extension list and the size ceiling both come from config rather than being
 * repeated here, because ReaderFactory reads the same list and a drift between the
 * two would mean either accepting a file nothing can read, or refusing one that
 * would have worked.
 *
 * The legacy `.xls` ceiling is deliberately NOT enforced here. It is a property of
 * how that format must be parsed, not of the upload, and the ingest job reports it
 * with its own error code and its own advice ("re-save as .xlsx"). Duplicating it
 * at the request layer would produce a validation error that says less.
 */
class StoreBiSourceRequest extends FormRequest
{
    public function rules(): array
    {
        $maxKb = max(1, (int) config('agent.bi.ingest.max_file_size_kb', 100 * 1024));

        /** @var array<int, string> $extensions */
        $extensions = config('agent.bi.ingest.allowed_extensions', []);

        return [
            'file' => [
                'required',
                // Extensions rather than MIME types. Browsers send
                // `application/octet-stream` for .ndjson and disagree with each
                // other about .csv (text/csv, application/vnd.ms-excel,
                // text/plain), so a MIME allowlist would refuse legitimate files
                // depending on which browser uploaded them. The reader is what
                // ultimately decides whether the bytes are what they claim, and it
                // fails with CORRUPT_FILE when they are not.
                File::types($extensions)->max($maxKb),
            ],
            // Optional: the owner may name the source instead of using the
            // filename. Datasets get their own names from the sheets.
            'name' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        $megabytes = (int) round(((int) config('agent.bi.ingest.max_file_size_kb', 100 * 1024)) / 1024);

        return [
            'file.required' => __('bi.validation.file_required'),
            'file.extensions' => __('bi.validation.file_extension'),
            'file.mimes' => __('bi.validation.file_extension'),
            'file.max' => __('bi.validation.file_size', ['size' => $megabytes]),
        ];
    }
}
