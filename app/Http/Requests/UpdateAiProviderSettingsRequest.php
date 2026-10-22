<?php

namespace App\Http\Requests;

use App\Services\Permissions\PermissionCatalog;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

#[ErrorBag('ai_provider')]
class UpdateAiProviderSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(PermissionCatalog::TEAM_SETTINGS_UPDATE);
    }

    public function rules(): array
    {
        return [
            'ai_provider' => ['required', 'string', Rule::in(config('agent.llm_providers'))],
            'ai_model' => ['nullable', 'string', 'max:100'],
            'ollama_base_url' => ['nullable', 'string', 'url', 'max:255'],
        ];
    }

    public function teamAttributes(): array
    {
        $data = $this->validated();

        return [
            'ai_provider' => $data['ai_provider'],
            'ai_model' => filled($data['ai_model'] ?? null) ? $data['ai_model'] : null,
            'ollama_base_url' => $data['ai_provider'] === 'ollama' && filled($data['ollama_base_url'] ?? null)
                ? rtrim($data['ollama_base_url'], '/')
                : null,
        ];
    }
}
