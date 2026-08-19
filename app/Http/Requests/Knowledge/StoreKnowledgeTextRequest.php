<?php

namespace App\Http\Requests\Knowledge;

use App\Models\KnowledgeSource;
use Illuminate\Foundation\Http\FormRequest;

class StoreKnowledgeTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [KnowledgeSource::class, $this->route('agent')]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'min:10'],
        ];
    }
}
