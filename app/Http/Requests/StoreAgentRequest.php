<?php

namespace App\Http\Requests;

use App\Models\Agent;
use App\Services\EscalationService;
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
            'language' => ['required', 'string', 'in:auto,ru,en,tj,uz'],
            'tone' => ['required', 'string', 'in:friendly,professional,casual,formal'],
            'greeting_message' => ['nullable', 'string', 'max:1000'],
            'fallback_message' => ['nullable', 'string', 'max:1000'],
            'max_length' => ['nullable', 'integer', 'min:50', 'max:4000'],
            'use_emojis' => ['boolean'],
            'custom_system_prompt' => ['nullable', 'string', 'max:5000'],
            'rules_instructions' => ['nullable', 'string', 'max:5000'],
            'on_not_found' => ['required', 'string', 'in:apologize,human_handoff,suggest_alternatives'],
            'escalation' => ['nullable', 'string', 'in:'.implode(',', EscalationService::SENSITIVITIES)],
            'capture_leads' => ['boolean'],
            'proactivity' => ['required', 'string', 'in:off,subtle,active'],
            'humanize_replies' => ['boolean'],
            'transcribe_voice' => ['boolean'],
        ];
    }

    /**
     * Map the flat form fields into the JSON column shapes used by the Agent model.
     */
    public function agentAttributes(): array
    {
        $data = $this->validated();

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'language' => $data['language'],
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
                // Absent from an older form post means "use the configured
                // default", not "off".
                'escalation' => $data['escalation'] ?? config('agent.escalation.default_sensitivity', 'conservative'),
            ],
            'capture_leads' => (bool) ($data['capture_leads'] ?? false),
            'proactivity' => $data['proactivity'],
            'humanize_replies' => (bool) ($data['humanize_replies'] ?? false),
            // Absent from an older form post means "leave it on" — an
            // unanswered voice note reads as a broken bot, so the safe default
            // is the capable one.
            'transcribe_voice' => (bool) ($data['transcribe_voice'] ?? true),
        ];
    }
}
