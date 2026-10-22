<?php

namespace App\Http\Requests\Bi;

use App\Models\BiSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreBiSourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', BiSource::class);
    }

    public function rules(): array
    {
        $maxKb = max(1, (int) config('agent.bi.ingest.max_file_size_kb', 100 * 1024));

        $extensions = config('agent.bi.ingest.allowed_extensions', []);

        return [
            'file' => [
                'required',

                File::types($extensions)->max($maxKb),
            ],

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
