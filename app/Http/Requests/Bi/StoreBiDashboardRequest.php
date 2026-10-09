<?php

namespace App\Http\Requests\Bi;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A board's own fields. Deliberately small — a dashboard is a name and a list
 * of widgets, and everything interesting is validated where it is used.
 *
 * `filters` is accepted as a free-form nested array and NOT validated against
 * any dataset here. See the migration's comment: a filter references a
 * `bi_columns` row, those rows are replaced by a rematerialization, and
 * validating on write would turn "the data was re-imported" into "your saved
 * board is rejected". SpecValidator checks them on every read instead, which is
 * where a stale reference can be reported per card rather than losing the save.
 */
class StoreBiDashboardRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_default' => ['nullable', 'boolean'],

            // Keyed by dataset id; each value a list of filter objects. The
            // depth is capped so a hand-rolled payload cannot make the JSON
            // column enormous, but the SHAPE inside is the query layer's
            // business.
            'filters' => ['nullable', 'array', 'max:50'],
            'filters.*' => ['array', 'max:20'],
            'filters.*.*' => ['array'],
            'filters.*.*.column' => ['required'],
            'filters.*.*.operator' => ['required', 'string', 'max:20'],
            'filters.*.*.value' => ['nullable'],
            'filters.*.*.values' => ['nullable', 'array', 'max:20'],
            'filters.*.*.values.*' => ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => __('bi.validation.dashboard_name_required'),
            'name.max' => __('bi.validation.dashboard_name_max'),
        ];
    }
}
