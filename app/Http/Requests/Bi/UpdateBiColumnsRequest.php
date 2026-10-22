<?php

namespace App\Http\Requests\Bi;

use App\Services\Bi\Schema\ColumnRole;
use App\Services\Bi\Schema\ColumnType;
use App\Services\Bi\Schema\SchemaEditor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBiColumnsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('dataset'));
    }

    public function rules(): array
    {
        return [
            'columns' => ['required', 'array', 'max:200'],
            'columns.*' => ['array'],
            'columns.*.type' => ['sometimes', Rule::in(ColumnType::values())],
            'columns.*.role' => ['sometimes', Rule::in(ColumnRole::values())],
            'columns.*.label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'columns.*.description' => ['sometimes', 'nullable', 'string', 'max:512'],
            'columns.*.date_format' => ['sometimes', 'nullable', Rule::in(SchemaEditor::selectableDateFormats())],
        ];
    }

    public function messages(): array
    {
        return [
            'columns.*.type.in' => __('bi.schema.invalid_type'),
            'columns.*.role.in' => __('bi.schema.invalid_role'),
            'columns.*.date_format.in' => __('bi.schema.invalid_date_format'),
        ];
    }
}
