<?php

namespace App\Http\Requests\Database;

use App\Models\DatabaseConnection;
use App\Services\Database\LiveProductSource;
use Illuminate\Foundation\Http\FormRequest;

class StoreMappingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [DatabaseConnection::class, $this->route('agent')]);
    }

    public function rules(): array
    {
        $rules = [
            'table' => ['required', 'string', 'max:255'],
            'mapping' => ['required', 'array'],

            'mapping.name' => ['required', 'string', 'max:128'],
        ];

        foreach (LiveProductSource::FIELDS as $field) {
            if ($field !== 'name') {
                $rules["mapping.{$field}"] = ['nullable', 'string', 'max:128'];
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'mapping.name.required' => __('agents.database.errors.missing_name'),
        ];
    }

    public function mapping(): array
    {
        $submitted = (array) $this->input('mapping', []);
        $mapping = [];

        foreach (LiveProductSource::FIELDS as $field) {
            $column = $submitted[$field] ?? null;

            if (is_string($column) && trim($column) !== '') {
                $mapping[$field] = trim($column);
            }
        }

        return $mapping;
    }
}
