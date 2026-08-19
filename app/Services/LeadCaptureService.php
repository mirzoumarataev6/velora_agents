<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use Illuminate\Support\Str;

/**
 * Owns everything about the create_lead function-calling tool: its neutral
 * definition (handed to the LLM provider), the prompt guidance that tells the
 * model when to use it, and the persistence of a captured lead.
 *
 * Kept separate from AgentReplyService so the reply pipeline only has to decide
 * "offer the tool?" and "a tool call came back — persist it", without carrying
 * the tool's schema and copy inline.
 */
class LeadCaptureService
{
    public const TOOL_NAME = 'create_lead';

    /**
     * The tool definition in the neutral shape LlmProviderInterface::generate()
     * accepts under $options['tools'].
     *
     * @return array{name: string, description: string, parameters: array}
     */
    public function toolDefinition(): array
    {
        return [
            'name' => self::TOOL_NAME,
            'description' => 'Record a sales lead / order. Call this as soon as the customer clearly wants to '
                .'place an order, buy something, book or reserve, or asks the business to contact them back '
                .'(e.g. leaves a phone number). Capture whatever details they have already given — do not '
                .'refuse to call it just because some fields are missing, and do not interrogate the customer '
                .'for every field before calling. Do NOT call it for general questions, price checks, or small talk.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'customer_name' => [
                        'type' => 'string',
                        'description' => "The customer's name, if they gave one.",
                    ],
                    'contact' => [
                        'type' => 'string',
                        'description' => 'Phone number, @username, email or other contact the business should use to reach them.',
                    ],
                    'items' => [
                        'type' => 'string',
                        'description' => 'What the customer wants to order or is asking about — product name(s), quantity, size, colour, etc.',
                    ],
                    'note' => [
                        'type' => 'string',
                        'description' => 'Any other useful detail: delivery address, preferred time, budget, special requests.',
                    ],
                ],
                // Deliberately empty: a partial lead (just a phone number, say) is
                // still worth capturing, so nothing is strictly required.
                'required' => [],
            ],
        ];
    }

    /**
     * Guidance appended to the system prompt when lead capture is enabled, so
     * the model understands it has the tool and roughly when to reach for it.
     */
    public function systemInstruction(): string
    {
        return implode("\n", [
            'LEAD CAPTURE:',
            '- You have a tool called "'.self::TOOL_NAME."\". Use it to record the customer's order or request "
                .'the moment they show clear intent to buy, order, book, or be contacted back.',
            '- Prefer to collect at least a way to reach them (phone or username) plus what they want, but if the '
                .'customer is ready to order, call the tool with whatever you have rather than stalling.',
            '- After the tool records the lead, confirm to the customer in one short, warm sentence that their '
                .'request has been received and someone will follow up. Never mention tools, functions, or internal systems.',
        ]);
    }

    /**
     * Persist a captured lead from the model's tool-call arguments.
     *
     * @param  array<string, mixed>  $args  Raw create_lead arguments.
     */
    public function persist(Agent $agent, ?Conversation $conversation, array $args, string $source): Lead
    {
        return $agent->leads()->create([
            'conversation_id' => $conversation?->id,
            'customer_name' => $this->clean($args['customer_name'] ?? null)
                ?? $conversation?->external_user_name,
            'contact' => $this->clean($args['contact'] ?? null),
            'items' => $this->clean($args['items'] ?? null),
            'note' => $this->clean($args['note'] ?? null),
            'source' => $source,
            'status' => 'new',
            'details' => $args,
        ]);
    }

    /**
     * A deterministic confirmation used only if the follow-up generation (which
     * normally writes the confirmation in the conversation's language) fails —
     * the lead is already saved by then, so the customer must still hear back.
     */
    public function confirmationFallback(Agent $agent): string
    {
        return $agent->response_format['lead_confirmation']
            ?? 'Спасибо! Мы записали вашу заявку и скоро свяжемся с вами.';
    }

    protected function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = Str::of($value)->trim()->limit(1000, '')->toString();

        return $value === '' ? null : $value;
    }
}
