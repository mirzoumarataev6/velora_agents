<?php

namespace App\Http\Requests\Bi;

use App\Models\BiDashboard;
use Illuminate\Foundation\Http\FormRequest;

class StoreBiDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('dashboard') ? $this->user()->can('update', $this->route('dashboard')) : $this->user()->can('create', BiDashboard::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_default' => ['nullable', 'boolean'],

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
