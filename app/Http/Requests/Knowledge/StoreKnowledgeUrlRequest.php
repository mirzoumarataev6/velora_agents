<?php

namespace App\Http\Requests\Knowledge;

use App\Models\KnowledgeSource;
use Illuminate\Foundation\Http\FormRequest;

class StoreKnowledgeUrlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [KnowledgeSource::class, $this->route('agent')]);
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'url', 'max:2048'],
        ];
    }
}
