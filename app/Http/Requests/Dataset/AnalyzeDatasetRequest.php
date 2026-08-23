<?php

namespace App\Http\Requests\Dataset;

use App\Models\Dataset;
use Illuminate\Foundation\Http\FormRequest;

class AnalyzeDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Dataset::class, $this->route('agent')]);
    }

    public function rules(): array
    {
        $extensions = implode(',', config('agent.dataset.allowed_extensions'));
        $maxKb = config('agent.dataset.max_file_size_kb');

        return [
            'file' => ['required', 'file', "max:{$maxKb}", "mimes:{$extensions}"],
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => __('agents.catalog.errors.bad_format', [
                'formats' => implode(', ', config('agent.dataset.allowed_extensions')),
            ]),
            'file.max' => __('agents.catalog.errors.too_large', [
                'size' => round(config('agent.dataset.max_file_size_kb') / 1024),
            ]),
        ];
    }
}
