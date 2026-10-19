<?php

namespace App\Http\Requests;

use App\Services\Permissions\PermissionCatalog;
use Illuminate\Foundation\Http\Attributes\ErrorBag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Валидирует форму "AI Provider" в настройках команды.
 */
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

    /**
     * Пустая строка в модели/base_url значит "не переопределять" — хранится
     * как NULL, а не как '', чтобы LlmProviderFactory однозначно отличал
     * "команда ничего не задала" от "команда явно задала пустое значение".
     *
     * @return array{ai_provider: string, ai_model: ?string, ollama_base_url: ?string}
     */
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
