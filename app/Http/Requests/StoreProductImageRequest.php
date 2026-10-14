<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('catalog.manage', $this->route('agent'));
    }

    public function rules(): array
    {
        $extensions = implode(',', config('agent.product_images.allowed_extensions'));
        $maxKb = config('agent.product_images.max_file_size_kb');

        return [
            'image' => ['required', 'file', "max:{$maxKb}", "mimes:{$extensions}"],
        ];
    }

    public function messages(): array
    {
        return [
            'image.mimes' => __('agents.catalog.product_images.errors.bad_format', [
                'formats' => implode(', ', config('agent.product_images.allowed_extensions')),
            ]),
            'image.max' => __('agents.catalog.product_images.errors.too_large', [
                'size' => round(config('agent.product_images.max_file_size_kb') / 1024, 1),
            ]),
        ];
    }
}
