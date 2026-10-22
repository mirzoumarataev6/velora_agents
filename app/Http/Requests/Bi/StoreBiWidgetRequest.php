<?php

namespace App\Http\Requests\Bi;

use App\Services\Bi\Dashboard\WidgetType;
use App\Services\Bi\Query\Aggregate;
use App\Services\Bi\Query\FilterOperator;
use App\Services\Bi\Query\SortDirection;
use App\Services\Bi\Query\TimeGrain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBiWidgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('dashboard'));
    }

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
