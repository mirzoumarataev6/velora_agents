<?php

namespace App\Http\Requests\Bi;

use App\Models\BiDashboard;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBiLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('dashboard'));
    }

    public function rules(): array
    {
        return [
            'layout' => ['required', 'array', 'max:'.BiDashboard::MAX_WIDGETS],
            'layout.*.id' => ['required', 'integer', 'min:1'],
            'layout.*.x' => ['required', 'integer', 'min:0', 'max:11'],
            'layout.*.y' => ['required', 'integer', 'min:0', 'max:10000'],
            'layout.*.w' => ['required', 'integer', 'min:2', 'max:12'],
            'layout.*.h' => ['required', 'integer', 'min:2', 'max:24'],
        ];
    }
}
