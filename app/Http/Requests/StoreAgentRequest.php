<?php

namespace App\Http\Requests;

use App\Models\Agent;
use App\Services\EscalationService;
use App\Services\Haggling\HagglingService;
use Illuminate\Foundation\Http\FormRequest;

class StoreAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $agent = $this->route('agent');

        return $agent ? $this->user()->can('update', $agent) : $this->user()->can('create', Agent::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'tone' => ['required', 'string', 'in:friendly,professional,casual,formal'],
            'greeting_message' => ['nullable', 'string', 'max:1000'],
            'fallback_message' => ['nullable', 'string', 'max:1000'],
            'max_length' => ['nullable', 'integer', 'min:50', 'max:4000'],
            'use_emojis' => ['boolean'],
            'internet_image_search' => ['boolean'],
            'custom_system_prompt' => ['nullable', 'string', 'max:5000'],
            'rules_instructions' => ['nullable', 'string', 'max:5000'],
            'on_not_found' => ['required', 'string', 'in:apologize,human_handoff,suggest_alternatives'],
            'escalation' => ['nullable', 'string', 'in:'.implode(',', EscalationService::SENSITIVITIES)],
            'proactivity' => ['required', 'string', 'in:off,subtle,active'],
            'haggling_enabled' => ['boolean'],
            'haggling_level' => ['nullable', 'string', 'in:'.implode(',', HagglingService::LEVELS)],

            'haggle_min_price_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
            'haggling_instructions' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function agentAttributes(): array
    {
        $data = $this->validated();

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'tone' => $data['tone'],
            'response_format' => [
                'greeting_message' => $data['greeting_message'] ?? null,
                'fallback_message' => $data['fallback_message'] ?? null,
                'max_length' => $data['max_length'] ?? null,
                'use_emojis' => (bool) ($data['use_emojis'] ?? false),
            ],
            'custom_system_prompt' => $data['custom_system_prompt'] ?? null,
            'rules_instructions' => $data['rules_instructions'] ?? null,
            'behavior' => [
                'on_not_found' => $data['on_not_found'],

                'escalation' => $data['escalation'] ?? config('agent.escalation.default_sensitivity', 'conservative'),
                'internet_image_search' => (bool) ($data['internet_image_search'] ?? false),
            ],
            'proactivity' => $data['proactivity'],
            'haggling_enabled' => (bool) ($data['haggling_enabled'] ?? false),
            'haggling_level' => $data['haggling_level'] ?? HagglingService::DEFAULT_LEVEL,

            'haggle_min_price_percent' => (int) ($data['haggle_min_price_percent'] ?? 100),
            'haggling_instructions' => $data['haggling_instructions'] ?? null,
        ];
    }
}
