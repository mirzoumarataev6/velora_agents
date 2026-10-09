<?php

namespace App\Http\Requests\Bi;

use App\Services\Bi\Schema\ColumnRole;
use App\Services\Bi\Schema\ColumnType;
use App\Services\Bi\Schema\SchemaEditor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The schema drawer's save.
 *
 * Every value here is validated against a CLOSED SET — the type and role enums, and
 * the short list of date patterns the schema editor offers. That is not belt-and-braces
 * politeness: `type` decides a column's DDL on the next rebuild and `date_format`
 * decides how every value in it is parsed, so both are exactly the kind of input that
 * must not be free text.
 *
 * Column IDs are NOT validated for existence here. SchemaEditor resolves them against
 * the dataset's own columns and ignores anything else, which is the stronger guarantee:
 * an id belonging to another owner's dataset is not merely rejected, it is not found.
 */
class UpdateBiColumnsRequest extends FormRequest
{
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
