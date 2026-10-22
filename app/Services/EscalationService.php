<?php

namespace App\Services;

use App\Jobs\SendEscalationNotificationJob;
use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Support\Str;

class EscalationService
{
    public const TOOL_NAME = 'flag_needs_human';

    public const SENSITIVITIES = ['off', 'conservative', 'balanced', 'sensitive'];

    public const SIGNALS = ['frustration', 'complaint', 'explicit_request', 'threatening_to_leave', 'fallback'];

    public function toolDefinition(): array
    {
        return [
            'name' => self::TOOL_NAME,
            'description' => 'Flag that this customer should be handed to a human colleague. '
                .'Call this ONLY when the customer is genuinely dissatisfied or explicitly wants a person: '
                .'they are angry or insulting, complaining about the product or the service they received, '
                .'saying the same problem again because the previous answer did not help, demanding a refund, '
                .'threatening to leave, to complain publicly or to go to a competitor, or plainly asking to '
                .'speak to a human, manager or operator. '
                .'Do NOT call it for an ordinary question, a price check, a negative fact ("no, we are closed '
                .'on Sunday"), mild confusion, a customer who simply rephrases a question, impatience about '
                .'delivery timing alone, or a single terse or blunt message. Being brief is not being angry. '
                .'When unsure, do not call it.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'signal' => [
                        'type' => 'string',
                        'enum' => ['frustration', 'complaint', 'explicit_request', 'threatening_to_leave'],
                        'description' => 'What was observed. Use "explicit_request" only when the customer '
                            .'actually asked for a human/manager/operator.',
                    ],
                    'confidence' => [
                        'type' => 'number',
                        'description' => 'How certain you are, from 0 to 1. Use 0.9+ only when the '
                            .'dissatisfaction is unmistakable (insults, explicit complaint, refund demand, '
                            .'threat to leave). Use below 0.6 if you are inferring it from tone alone.',
                    ],
                    'summary' => [
                        'type' => 'string',
                        'description' => 'One short sentence, in the customer\'s own language, saying what '
                            .'they are unhappy about. This is shown to the business owner.',
                    ],
                ],
                'required' => ['signal', 'confidence'],
            ],
        ];
    }

    public function systemInstruction(Agent $agent): string
    {
        $lines = [
            'HANDING OVER TO A PERSON:',
            '- You have a tool called "'.self::TOOL_NAME.'". It quietly alerts the business owner. '
                .'The customer never sees it and must never be told about tools or internal systems.',
            '- Call it only for genuine dissatisfaction: anger, insults, a real complaint about the product or '
                .'service, the same unresolved problem raised again, a refund demand, a threat to leave or '
                .'complain publicly, or a direct request for a human, manager or operator.',
            '- Do NOT call it for ordinary questions, price checks, mild confusion, a rephrased question, or a '
                .'short blunt message. A calm complaint-shaped question ("is it always this slow?") is not a complaint.',
            '- Judge the customer\'s LATEST message in the context of the conversation. Russian, Tajik, English '
                .'and mixed Tajik-Russian all count — judge meaning and tone, not the language.',
            '- CRUCIAL: when you call it, still answer the customer in the same turn. Acknowledge what went wrong '
                .'in one warm sentence, apologise briefly if that fits, help as far as you can, and tell them a '
                .'colleague will join shortly. Never go silent and never reply with only an apology.',
        ];

        if ($this->sensitivityFor($agent) === 'conservative') {
            $lines[] = '- Err strongly towards NOT calling it. A wrong alert costs the owner more than a missed one.';
        }

        return implode("\n", $lines);
    }

    public function enabledFor(Agent $agent): bool
    {
        return $this->sensitivityFor($agent) !== 'off';
    }

    public function sensitivityFor(Agent $agent): string
    {
        $configured = $agent->behavior['escalation']
            ?? config('agent.escalation.default_sensitivity', 'conservative');

        return in_array($configured, self::SENSITIVITIES, true) ? $configured : 'conservative';
    }

    public function shouldEscalate(Agent $agent, array $args): bool
    {
        $sensitivity = $this->sensitivityFor($agent);
        if ($sensitivity === 'off') {
            return false;
        }

        $signal = (string) ($args['signal'] ?? '');
        if (! in_array($signal, self::SIGNALS, true)) {
            return false;
        }

        $confidence = (float) ($args['confidence'] ?? 0);

        $threshold = $signal === 'explicit_request'
            ? (float) config('agent.escalation.explicit_request_threshold', 0.5)
            : (float) (config('agent.escalation.thresholds')[$sensitivity] ?? 0.85);

        return $confidence >= $threshold;
    }

    public function escalate(Agent $agent, Conversation $conversation, array $args, ?string $signal = null): void
    {
        $alreadyFlagged = $conversation->needs_human_at !== null;
        $withinWindow = $alreadyFlagged && $conversation->needs_human_at->gt(
            now()->subMinutes((int) config('agent.escalation.renotify_after_minutes', 180))
        );

        $conversation->forceFill([
            'needs_human_at' => now(),
            'needs_human_signal' => $signal ?? (string) ($args['signal'] ?? 'frustration'),
            'needs_human_summary' => Str::limit(trim((string) ($args['summary'] ?? '')), 480) ?: null,

            'status' => $conversation->status === 'closed' ? 'closed' : 'human_takeover',
        ])->save();

        if (! $withinWindow) {
            SendEscalationNotificationJob::dispatch($conversation);
        }
    }
}
