<?php

namespace App\Http\Requests\Bi;

use App\Services\Bi\Dashboard\WidgetType;
use App\Services\Bi\Query\Aggregate;
use App\Services\Bi\Query\FilterOperator;
use App\Services\Bi\Query\SortDirection;
use App\Services\Bi\Query\TimeGrain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A card's definition.
 *
 * WHAT IS VALIDATED HERE AND WHAT IS NOT is the whole design of this class.
 *
 * Validated here: the CLOSED SETS. A widget type, an aggregate, a grain, a
 * filter operator and a sort direction all come from enums, and a value outside
 * one of them is a malformed request rather than a query that happens to fail —
 * so it is refused with a field-level message the form can show next to the
 * offending input.
 *
 * NOT validated here: whether `c7` exists in this dataset, whether SUM makes
 * sense on it, and whether the type can draw the result. Those need the
 * dataset's registry, they are already checked by SpecValidator and
 * WidgetSpecBuilder, and re-checking them at the request layer would mean two
 * implementations of the same rule drifting apart. More importantly it would
 * make saving a widget fail for a dataset that is MID-REBUILD, which is a
 * perfectly reasonable moment to be editing a board.
 *
 * So: shape and vocabulary here, meaning downstream.
 */
class StoreBiWidgetRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'bi_dataset_id' => ['required', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(WidgetType::values())],

            'spec' => ['required', 'array'],
            'spec.dimensions' => ['nullable', 'array', 'max:24'],
            'spec.dimensions.*.column' => ['required'],
            'spec.dimensions.*.grain' => ['nullable', Rule::in(TimeGrain::values())],

            'spec.measures' => ['nullable', 'array', 'max:24'],
            'spec.measures.*.aggregate' => ['required', Rule::in(Aggregate::values())],
            // Nullable because COUNT takes none — the pairing is checked by
            // SpecValidator, which knows which aggregates need a column.
            'spec.measures.*.column' => ['nullable'],

            'spec.sorts' => ['nullable', 'array', 'max:8'],
            'spec.sorts.*.field' => ['required', 'string', 'max:8'],
            'spec.sorts.*.direction' => ['nullable', Rule::in(SortDirection::values())],

            'spec.limit' => ['nullable', 'integer', 'min:1'],

            'filters' => ['nullable', 'array', 'max:20'],
            'filters.*.column' => ['required'],
            'filters.*.operator' => ['required', Rule::in(FilterOperator::values())],
            'filters.*.value' => ['nullable'],
            'filters.*.values' => ['nullable', 'array', 'max:20'],
            'filters.*.values.*' => ['nullable'],

            'options' => ['nullable', 'array', 'max:20'],

            // Accepted on create so the editor can place a card where the owner
            // dropped it; omitted, BiWidget::defaultSlot() puts it at the bottom.
            'grid_x' => ['nullable', 'integer', 'min:0', 'max:11'],
            'grid_y' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'grid_w' => ['nullable', 'integer', 'min:2', 'max:12'],
            'grid_h' => ['nullable', 'integer', 'min:2', 'max:24'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => __('bi.validation.widget_title_required'),
            'type.in' => __('bi.validation.widget_type_unknown'),
            'spec.required' => __('bi.validation.widget_spec_required'),
        ];
    }
}
