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
            // Names the pending upload to import. Validated as an opaque token
            // here; DatasetController resolves it inside the agent's OWN pending
            // directory, so a token cannot address another tenant's upload.
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{16,64}$/'],

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

    /**
     * The mapping with "not mapped" entries dropped, so the importer only ever
     * sees fields the owner actually assigned a column to.
     *
     * @return array<string, mixed>
     */
    public function mapping(): array
    {
        return array_filter(
            (array) $this->input('mapping', []),
            fn ($value) => $value !== null && $value !== '' && $value !== [],
        );
    }
}
