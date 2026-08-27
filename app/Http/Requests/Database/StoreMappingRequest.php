<?php

namespace App\Http\Requests\Database;

use App\Models\DatabaseConnection;
use App\Services\Database\LiveProductSource;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Choosing the products table and saying what its columns mean.
 *
 * Shape only is validated here. Whether the table and each column ACTUALLY
 * exist is settled in the controller against a fresh read of the customer's
 * information_schema — a rule string cannot know that, and it is the check that
 * keeps an identifier from ever reaching the SQL builder unverified.
 */
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
            // Without a product name a row is not a product.
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

    /**
     * The mapping with unassigned fields dropped, and any key outside the field
     * vocabulary discarded — so a hand-crafted request cannot smuggle an extra
     * "column" into the stored mapping.
     *
     * @return array<string, string>
     */
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
