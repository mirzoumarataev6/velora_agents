<?php

namespace App\Http\Requests\Dataset;

use App\Models\Dataset;
use App\Services\Dataset\ColumnMapper;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a mapping-step preview request: same token + mapping shape as
 * ImportDatasetRequest, but this one writes nothing — it only tells the
 * owner, before they commit to 'update', how many existing rows the file in
 * front of them doesn't match.
 */
class PreviewDatasetUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [Dataset::class, $this->route('agent')]);
    }

    public function rules(): array
    {
        $rules = [
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

    /**
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
