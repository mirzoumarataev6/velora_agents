<?php

namespace App\Http\Requests\Dataset;

use App\Models\Dataset;
use App\Services\Dataset\ColumnMapper;
use Illuminate\Foundation\Http\FormRequest;

class ImportDatasetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Dataset::class, $this->route('agent')]);
    }

    public function rules(): array
    {
        $rules = [

            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{16,64}$/'],

            'mode' => ['nullable', 'string', 'in:update,replace'],

            'mark_missing_out_of_stock' => ['nullable', 'boolean'],

            'mapping' => ['required', 'array'],
            'mapping.extra' => ['nullable', 'array'],
            'mapping.extra.*' => ['integer', 'min:0'],
        ];

        foreach (ColumnMapper::FIELDS as $field) {
            if ($field !== 'extra') {
                $rules["mapping.{$field}"] = ['nullable', 'integer', 'min:0'];
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'token.required' => __('agents.catalog.errors.upload_expired'),
            'token.regex' => __('agents.catalog.errors.upload_expired'),
        ];
    }

    public function mapping(): array
    {
        return array_filter(
            (array) $this->input('mapping', []),
            fn ($value) => $value !== null && $value !== '' && $value !== [],
        );
    }

    public function mode(): string
    {
        return $this->input('mode') === 'update' ? 'update' : 'replace';
    }

    public function markMissingOutOfStock(): bool
    {
        return $this->boolean('mark_missing_out_of_stock', true);
    }
}
