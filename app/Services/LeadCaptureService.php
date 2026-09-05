<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Services\Leads\PhoneNumberDetector;
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
     * Marks the assistant turn that asked for a phone number, so the ask
     * happens once per conversation and not on every order attempt.
     *
     * Stored in the message's own meta rather than on the conversation: it is a
     * fact about a turn that actually happened, it needs no migration, and a
     * conversation column would have to be reset by hand if the customer came
     * back a week later with a second order.
     */
    public const ASKED_FOR_PHONE = 'phone_requested';

    /** How far back to look for a number the customer already gave. */
    protected const PHONE_LOOKBACK = 30;

    public function __construct(protected PhoneNumberDetector $phones) {}

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
                .'(e.g. leaves a phone number). Ask for a phone number first if you do not have one yet (see '
                .'LEAD CAPTURE in your instructions), but if the customer will not give one, still call this — '
                .'an order without a phone number is an order. Capture whatever details they have already given '
                .'— do not refuse to call it just because some fields are missing, and do not interrogate the '
                .'customer for every field before calling. Do NOT call it for general questions, price checks, '
                .'or small talk.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'customer_name' => [
                        'type' => 'string',
                        'description' => "The customer's name, if they gave one.",
                    ],
                    'phone' => [
                        'type' => 'string',
                        'description' => "The customer's phone number, exactly as they wrote it, if they have given "
                            .'one anywhere in this conversation. Leave it out if they have not given one or declined '
                            .'to — never invent, guess, or reuse a number from somewhere else.',
                    ],
                    'contact' => [
                        'type' => 'string',
                        'description' => 'Any OTHER way to reach them — @username, email, "пишите сюда". Not the phone number.',
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
            '',
            'ASK FOR A PHONE NUMBER FIRST — but never let it block the order:',
            '- Before recording an order, if the customer has NOT given a phone number anywhere in this '
                .'conversation, ask for one. Once, plainly, in their language: "Чтобы мы могли с вами связаться, '
                .'оставьте, пожалуйста, номер телефона." Then wait for their answer before recording anything.',
            '- If they give one, pass it in the "phone" argument and tell them you will call to confirm.',
            '- If they refuse, ignore the question, or say to just write to them here — RECORD THE ORDER ANYWAY, '
                .'with no phone argument. Do not ask a second time, do not argue, and never make the order '
                .'conditional on a number. Losing the sale over a phone number is the worst outcome available.',
            '- Never say you will call someone whose number you do not have. Without one, tell them you will '
                .'follow up right here in the chat.',
            '- If they already gave a number earlier, do not ask again — use it.',
            '',
            '- After the tool records the lead, confirm to the customer in one short, warm sentence that their '
                .'request has been received and how they will be contacted. Never mention tools, functions, or '
                .'internal systems.',
            '',
            'NEVER SAY AN ORDER IS PLACED UNLESS YOU PLACED IT:',
            '- "Заявка принята", "заказ оформлен", "я оформлю", "зафиксирую", "қабул шуд" and anything like them '
                .'are statements of FACT about this business\'s records. They are true only after you have called '
                .'"'.self::TOOL_NAME.'" in this same turn AND the tool result said it was recorded.',
            '- Saying it because it sounds reassuring, or because the customer asked you to, records nothing. The '
                .'shop then has no order, the customer believes they placed one, and nobody finds out until they '
                .'ask where it is.',
            '- If you have not called the tool yet and the customer is ready, CALL IT NOW rather than describing '
                .'what you are about to do.',
            '- If the tool result says the order could not be recorded, say so honestly and offer a colleague. '
                .'Never paper over it with a confirmation.',
        ]);
    }

    /**
     * Whether this order attempt should pause to ask for a phone number.
     *
     * Deliberately enforced here rather than trusted to the prompt. The model is
     * told to ask first, and a model mid-order with everything else in hand
     * routinely decides the number is optional and records without it — and once
     * the order exists there is no natural moment to ask again. So the first
     * order attempt with no number anywhere is turned into the question.
     *
     * It fires ONCE. Whatever the customer says next — a number, a refusal, or
     * something else entirely — the following attempt records the order. That is
     * what keeps requirement "ask" from quietly becoming requirement "insist".
     *
     * @param  array<int, array<string, mixed>>  $leadCalls
     */
    public function shouldAskForPhoneFirst(?Conversation $conversation, array $leadCalls): bool
    {
        if ($conversation === null) {
            return false;
        }

        if ($this->phoneFor($conversation, $leadCalls) !== null) {
            return false;
        }

        return ! $this->alreadyAskedForPhone($conversation);
    }

    /**
     * The customer's phone number for this order: what the model passed, else
     * what they typed earlier in the conversation.
     *
     * The history is searched because the model frequently omits a number the
     * customer gave three messages ago — it has already "seen" it, so it does
     * not repeat it, and the order would be filed as uncallable.
     *
     * @param  array<int, array<string, mixed>>  $leadCalls
     */
    public function phoneFor(?Conversation $conversation, array $leadCalls): ?string
    {
        foreach ($leadCalls as $call) {
            $args = $call['arguments'] ?? [];

            $phone = $this->phones->findIn([
                is_string($args['phone'] ?? null) ? $args['phone'] : null,
                is_string($args['contact'] ?? null) ? $args['contact'] : null,
            ]);

            if ($phone !== null) {
                return $phone;
            }
        }

        return $conversation === null ? null : $this->phones->findIn(
            $conversation->messages()
                ->where('role', 'user')
                ->orderByDesc('id')
                ->take(self::PHONE_LOOKBACK)
                ->pluck('content')
        );
    }

    /** Whether the assistant has already asked this customer for a number. */
    public function alreadyAskedForPhone(Conversation $conversation): bool
    {
        return $conversation->messages()
            ->where('role', 'assistant')
            ->orderByDesc('id')
            ->take(self::PHONE_LOOKBACK)
            ->get(['meta'])
            ->contains(fn (Message $message) => (bool) ($message->meta[self::ASKED_FOR_PHONE] ?? false));
    }

    /**
     * What the model is told when the order is being held back for one turn.
     *
     * It is told the order is NOT saved, because a model that believes the order
     * is already recorded asks for the number as an afterthought and the
     * customer answers something else entirely.
     */
    public function phoneRequestInstruction(): string
    {
        return implode("\n", [
            'THE ORDER HAS NOT BEEN RECORDED YET, and you have no phone number for this customer.',
            '- Ask them for one now, in one short warm sentence in their language, saying why you want it: so the '
                .'business can contact them about the order. For example: "Чтобы мы могли с вами связаться, '
                .'оставьте, пожалуйста, номер телефона."',
            '- Confirm briefly what they are ordering in the same message, so it is clear nothing is lost.',
            '- Do NOT say the order is placed, accepted or recorded yet, and do not promise a call.',
            '- Do not pressure them. If they say no next turn, the order will be recorded without a number and '
                .'that is completely fine.',
        ]);
    }

    /**
     * What the model is told once the order IS recorded — and the difference is
     * the whole point: never promise a call to someone whose number you do not
     * have.
     */
    public function confirmationInstruction(bool $hasPhone): string
    {
        return $hasPhone
            ? 'Order recorded, WITH the customer\'s phone number. Confirm it warmly in one short sentence and say '
                .'the business will CALL them to confirm — e.g. "Спасибо! Мы вам позвоним для подтверждения заказа." '
                .'Use their own language and your own wording.'
            : 'Order recorded, WITHOUT a phone number — the customer did not give one, and that is fine. Confirm it '
                .'warmly in one short sentence and say you will follow up HERE IN THIS CHAT — e.g. "Хорошо, заявка '
                .'принята! Мы свяжемся с вами здесь, в чате." Do NOT promise a phone call, do NOT ask for a number '
                .'again, and do not apologise for not having one. Use their own language and your own wording.';
    }

    /**
     * Persist a captured lead from the model's tool-call arguments.
     *
     * @param  array<string, mixed>  $args  Raw create_lead arguments.
     */
    public function persist(Agent $agent, ?Conversation $conversation, array $args, string $source, ?string $phone = null): Lead
    {
        return $agent->leads()->create([
            'conversation_id' => $conversation?->id,
            'customer_name' => $this->clean($args['customer_name'] ?? null)
                ?? $conversation?->external_user_name,
            // Null is a real, recorded answer here: the customer was asked and
            // declined. It is what puts "только чат" on the owner's panel.
            'phone' => $phone ?? $this->phoneFor($conversation, [['arguments' => $args]]),
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
    /**
     * Sent instead of a confirmation when the order genuinely could not be
     * saved. Says what is true and what happens next, and promises nothing the
     * business cannot deliver.
     */
    public function orderFailureMessage(Agent $agent): string
    {
        return $agent->response_format['order_failure_message']
            ?? 'Извините, у меня сейчас техническая проблема с оформлением заявки. '
                .'Я передал ваш запрос коллеге — с вами свяжутся, чтобы всё оформить.';
    }

    /**
     * What the model is told when the order could NOT be written.
     *
     * There is no version of this where the customer should hear "заявка
     * принята". The write failed, so no order exists, and a confirmation would
     * be a promise the business cannot keep — which is precisely the failure
     * this instruction exists to stop repeating.
     */
    public function failureInstruction(): string
    {
        return 'The order could NOT be recorded — the write failed. Do NOT tell the customer their order is '
            .'placed, accepted or registered; that would be untrue. Instead: apologise briefly in one sentence, '
            .'say you are having a technical problem saving it, and tell them a colleague will contact them to '
            .'complete the order. Keep everything they told you in the conversation so it is not lost.';
    }

    /**
     * Record an order the assistant has already promised the customer.
     *
     * The last line of defence, and deliberately blunt. If a reply tells a
     * customer their order is accepted, an order has to exist — the alternative
     * is what happened in production: a shop that believes it has no orders
     * while its customers believe they have placed three.
     *
     * The details are thin because they were never captured. That is fine: the
     * conversation is linked, and an owner with a lead pointing at the right
     * chat can read what was wanted in ten seconds. A thin order is recoverable;
     * a missing one is not.
     */
    public function recordPromisedOrder(Agent $agent, ?Conversation $conversation, string $source, ?string $phone = null): Lead
    {
        return $agent->leads()->create([
            'conversation_id' => $conversation?->id,
            'customer_name' => $conversation?->external_user_name,
            'phone' => $phone,
            'items' => null,
            'note' => 'Заявка создана автоматически: ассистент подтвердил заказ клиенту. Детали — в переписке.',
            'source' => $source,
            'status' => 'new',
            'details' => ['auto_recorded' => true],
        ]);
    }

    /**
     * Asked when the model could not write the question itself. Having asked at
     * all is what matters; a templated sentence still asks.
     */
    public function phoneRequestFallback(Agent $agent): string
    {
        return $agent->response_format['phone_request_message']
            ?? 'Чтобы мы могли с вами связаться, оставьте, пожалуйста, номер телефона.';
    }

    public function confirmationFallback(Agent $agent, bool $hasPhone = true): string
    {
        // The owner's own wording is used only when a call can actually be
        // promised — it almost always says "мы свяжемся"/"перезвоним", which is
        // the one thing that must not be said to someone with no number on file.
        if ($hasPhone) {
            return $agent->response_format['lead_confirmation']
                ?? 'Спасибо! Мы вам позвоним для подтверждения заказа.';
        }

        return 'Хорошо, заявка принята! Мы свяжемся с вами здесь, в чате.';
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
