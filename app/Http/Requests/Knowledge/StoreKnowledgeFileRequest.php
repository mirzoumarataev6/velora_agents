<?php

namespace App\Http\Requests\Knowledge;

use App\Models\KnowledgeSource;
use Illuminate\Foundation\Http\FormRequest;

class StoreKnowledgeFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [KnowledgeSource::class, $this->route('agent')]);
    }

    public function rules(): array
    {
        $extensions = implode(',', config('agent.uploads.allowed_extensions'));
        $maxKb = config('agent.uploads.max_file_size_kb');

        return [
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', "max:{$maxKb}", "mimes:{$extensions}"],
        ];
    }

    public function messages(): array
    {
        return [
            'files.*.mimes' => 'Допустимые форматы: '.implode(', ', config('agent.uploads.allowed_extensions')).'.',
            'files.*.max' => 'Файл слишком большой (максимум '.round(config('agent.uploads.max_file_size_kb') / 1024).' МБ).',
        ];
    }
}
