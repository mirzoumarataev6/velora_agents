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

            // Absent means 'replace' — DatasetController::import()'s own
            // long-standing behavior, unchanged for any caller that
            // predates this field. The Каталог tab itself always sends one
            // explicitly now, defaulting its own button to 'update'.
            'mode' => ['nullable', 'string', 'in:update,replace'],

            // 'update' only — whether a row the file no longer lists gets
            // marked stock=0. Defaults true; the owner can uncheck it once
            // they've seen how many rows that would affect.
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

    /** 'replace' unless the caller explicitly asked for 'update' — see the rule above for why that stays the silent default. */
    public function mode(): string
    {
        return $this->input('mode') === 'update' ? 'update' : 'replace';
    }

    /** True unless the owner explicitly unchecked it at the mapping step — see the rule above. */
    public function markMissingOutOfStock(): bool
    {
        return $this->boolean('mark_missing_out_of_stock', true);
    }
}
