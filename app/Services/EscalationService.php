<?php

namespace App\Services;

use App\Jobs\SendEscalationNotificationJob;
use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Support\Str;

/**
 * Owns the "this customer needs a person" decision: the tool the model calls,
 * the prompt guidance that keeps it from crying wolf, the confidence bar, and
 * the flag + owner alert once it fires.
 *
 * Kept beside LeadCaptureService and shaped the same way, so the reply pipeline
 * only has to decide "offer the tool?" and "a call came back — act on it".
 *
 * The signal rides on the generation that was happening anyway: no separate
 * classification request, so a calm conversation costs exactly what it did
 * before this existed.
 */
class EscalationService
{
    public const TOOL_NAME = 'flag_needs_human';

    /** Sensitivities an agent may be set to. `off` disables detection entirely. */
    public const SENSITIVITIES = ['off', 'conservative', 'balanced', 'sensitive'];

    /** Signals the model may report, plus the one the pipeline raises itself. */
    public const SIGNALS = ['frustration', 'complaint', 'explicit_request', 'threatening_to_leave', 'fallback'];

    /**
     * The tool definition, in the neutral shape LlmProviderInterface::generate()
     * accepts under $options['tools'].
     *
     * The description carries the precision bar, because that is where the model
     * actually decides: it names what does NOT count as loudly as what does.
     *
     * @return array{name: string, description: string, parameters: array}
     */
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

    /**
     * Guidance appended to the system prompt when escalation is enabled.
     *
     * Two jobs: keep the bar high, and make sure that flagging does not turn
     * into going quiet. The customer must still get a real, warm answer in the
     * same turn — the flag is for the owner, and is never mentioned aloud.
     */
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

    /** Whether this agent has detection switched on at all. */
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

    /**
     * Does this tool call clear the bar for the agent's sensitivity?
     *
     * The model's own confidence is the only thing it reports, and self-reported
     * confidence is generously calibrated — which is precisely why the default
     * bar sits at 0.85 rather than at a "more than half sure" 0.5.
     *
     * @param  array<string, mixed>  $args
     */
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

        // A stated request is an observation, not an inference, so it clears a
        // lower bar than a mood read from tone.
        $threshold = $signal === 'explicit_request'
            ? (float) config('agent.escalation.explicit_request_threshold', 0.5)
            : (float) (config('agent.escalation.thresholds')[$sensitivity] ?? 0.85);

        return $confidence >= $threshold;
    }

    /**
     * Flag the conversation and alert the owner.
     *
     * Idempotent within a window: an already-flagged conversation is not
     * re-announced on every further angry message, or the owner would be
     * notified into ignoring the bell.
     *
     * @param  array<string, mixed>  $args
     */
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
            // Answering is now a person's job. Closed conversations are left
            // closed — an escalation should not silently reopen an archive.
            'status' => $conversation->status === 'closed' ? 'closed' : 'human_takeover',
        ])->save();

        if (! $withinWindow) {
            SendEscalationNotificationJob::dispatch($conversation);
        }
    }
}
