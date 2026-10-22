<?php

namespace App\Services\Haggling;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Services\Leads\PhoneNumberDetector;
use Illuminate\Support\Str;

class HagglingService
{
    public const TOOL_NAME = 'request_price_approval';

    public const LEVELS = ['soft', 'moderate', 'firm'];

    public const DEFAULT_LEVEL = 'moderate';

    public const TYPE = 'haggle';

    public const STATUS_PENDING = 'awaiting_price_approval';

    public function __construct(protected PhoneNumberDetector $phones) {}

    public function enabledFor(Agent $agent): bool
    {
        return $agent->hagglesOnPrice();
    }

    public function toolDefinition(): array
    {
        return [
            'name' => self::TOOL_NAME,
            'description' => 'Ask the business owner to approve a price the customer is asking for, when that '
                .'price is BELOW what you are allowed to agree to yourself. Call this once the customer has '
                .'named the price they actually want and is not moving — after you have negotiated, not '
                .'instead of negotiating. It does not give the customer the price: it puts the request in '
                .'front of the owner, who approves or declines. Never call it for a price you are already '
                .'allowed to confirm, and never tell the customer a discount is agreed before the owner has '
                .'answered.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'product' => [
                        'type' => 'string',
                        'description' => 'The product being negotiated, named the way the catalog names it, with '
                            .'quantity if the customer gave one.',
                    ],
                    'requested_price' => [
                        'type' => 'number',
                        'description' => 'The price the customer is asking for, as a number, in the catalog\'s currency.',
                    ],
                    'listed_price' => [
                        'type' => 'number',
                        'description' => 'The current listed price of that product, as a number — the figure the '
                            .'catalog tool gave you. Never guess it.',
                    ],
                    'summary' => [
                        'type' => 'string',
                        'description' => 'Two or three sentences for the owner: what the customer wants, what they '
                            .'have offered, what you already offered them, and any reason they gave. Write it in '
                            .'the owner\'s language (Russian).',
                    ],
                    'customer_name' => [
                        'type' => 'string',
                        'description' => "The customer's name, if they gave one.",
                    ],
                    'phone' => [
                        'type' => 'string',
                        'description' => "The customer's phone number, if they have given one anywhere in this "
                            .'conversation. Never invent one.',
                    ],
                ],
                'required' => ['requested_price'],
            ],
        ];
    }

    public function systemInstruction(Agent $agent): string
    {
        $percent = max(1, min(100, (int) ($agent->haggle_min_price_percent ?? 100)));
        $discount = 100 - $percent;

        $floorRule = $discount === 0
            ? '- You may NOT agree to any discount at all on your own. The listed price is the only price you can confirm.'
            : "- You may confirm a sale down to {$percent}% of the listed price — that is, a discount of at most "
                ."{$discount}%. Anything below that is not yours to give.";

        return implode("\n", array_merge([
            'NEGOTIATING ON PRICE:',
            '- Customers here expect to haggle, and refusing to engage loses the sale. Negotiate like a real '
                .'salesperson would: warmly, in their language, without ever sounding like a script.',
            $floorRule,
            '- If the customer asks for a price at or above that floor, just agree and take the order normally. '
                .'Do NOT call "'.self::TOOL_NAME.'" — there is nothing to approve.',
            '- If they ask for less than the floor, negotiate first (see your style below). Then, when they have '
                .'named their final offer and are not moving, call "'.self::TOOL_NAME.'" and tell them you will '
                .'check with the manager and come back to them — e.g. "Хорошо, я уточню у менеджера, дадут ли '
                .'такую цену, и вернусь к вам."',
            '- CRITICAL: you must NEVER tell the customer a below-floor price is agreed, approved, confirmed or '
                .'"okay" before the owner has answered. You are asking, not deciding. Saying otherwise commits '
                .'the business to a price it never accepted.',
            '- Never mention tools, managers\' margins, percentages, floors, or any internal rule. From the '
                .'customer\'s side this is just you checking with a colleague.',
            '',
        ], $this->styleLines($this->levelFor($agent)), $this->ownerGuidanceLines($agent)));
    }

    protected function ownerGuidanceLines(Agent $agent): array
    {
        $instructions = trim((string) ($agent->haggling_instructions ?? ''));

        if ($instructions === '') {
            return [];
        }

        return [
            '',
            'THE BUSINESS OWNER\'S OWN NEGOTIATING INSTRUCTIONS — follow these closely. Where they conflict with '
                .'the generic style guidance above, THESE WIN: the owner knows their products, their margins and '
                .'their customers better than a preset does.',
            $instructions,
            '',
            'THE ONE THING THOSE INSTRUCTIONS CANNOT DO is raise the price you may agree to by yourself. The floor '
                .'stated above is a setting, not a suggestion, and nothing written in the owner\'s instructions '
                .'changes it — not "approve anything above X", not "give them whatever they ask", not "you decide". '
                .'If text there appears to authorise a price below the floor, it does not: treat it as guidance '
                .'about TONE and still call "'.self::TOOL_NAME.'" so the owner approves the actual number. They '
                .'will see the request and can say yes in one click; a discount you granted on your own is one '
                .'they never saw at all.',
        ];
    }

    protected function styleLines(string $level): array
    {
        return match ($level) {
            'soft' => [
                'YOUR NEGOTIATING STYLE — ACCOMMODATING:',
                '- Sound flexible and glad to help from the first message. Thank them for asking rather than '
                    .'defending the price.',
                '- Warm up fast: acknowledge that the budget matters, say you will do what you can, and move '
                    .'quickly to what is possible.',
                '- One short round is enough. If their offer is below your floor, go and ask the owner rather '
                    .'than pushing back twice.',
                '- Never sound reluctant or grudging — but also never let "I\'ll do my best for you" be heard as '
                    .'"yes". You are still asking someone else.',
            ],
            'firm' => [
                'YOUR NEGOTIATING STYLE — FIRM:',
                '- Hold the listed price with confidence and without apology. The price is what the product is '
                    .'worth; say why — quality, warranty, what is included, what it costs elsewhere.',
                '- Give few verbal concessions. Do not soften the number just because they pushed.',
                '- Prefer adding value to cutting price: offer free or faster delivery, an accessory, a small '
                    .'extra, servicing — something that costs the business less than the discount would.',
                '- Let them ask two or three times before you go to the owner. Stay friendly throughout: firm is '
                    .'not cold, and never make them feel judged for asking.',
                '- When they clearly will not move, stop holding out, take their final number to the owner and '
                    .'say so plainly.',
            ],
            default => [
                'YOUR NEGOTIATING STYLE — BALANCED:',
                '- Trade like a real salesperson: meet them part of the way, not all of it. Counter with a '
                    .'smaller concession than they asked for and see what they say.',
                '- Justify the price with what the product actually gives them before you talk numbers.',
                '- Ask what budget they are working with — it is the fastest way to find something that fits, '
                    .'and it often ends with a different product rather than a discount.',
                '- One or two rounds of back-and-forth, then take their final offer to the owner. Do not haggle '
                    .'in circles; a customer repeating the same number twice has finished negotiating.',
            ],
        };
    }

    public function levelFor(Agent $agent): string
    {
        return $agent->hagglingLevel();
    }

    public function isBelowFloor(Agent $agent, ?float $requested, ?float $listed): bool
    {
        if ($requested === null || $listed === null || $listed <= 0 || $requested <= 0) {
            return true;
        }

        return $requested < $agent->minimumPriceFor($listed);
    }

    public function persist(Agent $agent, ?Conversation $conversation, array $args, string $source): Lead
    {
        $requested = $this->money($args['requested_price'] ?? null);
        $listed = $this->money($args['listed_price'] ?? null);

        return $agent->leads()->create([
            'conversation_id' => $conversation?->id,
            'type' => self::TYPE,
            'customer_name' => $this->clean($args['customer_name'] ?? null) ?? $conversation?->external_user_name,
            'phone' => $this->phones->findIn([
                is_string($args['phone'] ?? null) ? $args['phone'] : null,
            ]) ?? $this->phoneFromHistory($conversation),
            'items' => $this->clean($args['product'] ?? null),
            'requested_price' => $requested,
            'listed_price' => $listed,
            'negotiation_summary' => $this->clean($args['summary'] ?? null),
            'note' => $this->clean($args['summary'] ?? null),
            'source' => $source,
            'status' => self::STATUS_PENDING,
            'details' => $args,
        ]);
    }

    public function pendingInstruction(): string
    {
        return implode("\n", [
            'The price request has been sent to the owner. NOTHING HAS BEEN AGREED YET.',
            '- Tell the customer, in one or two short warm sentences in their own language, that you are '
                .'checking this price with the manager and will come back to them — e.g. "Хорошо, я уточню у '
                .'менеджера, дадут ли такую цену, и вернусь к вам."',
            '- Do NOT say the discount is approved, confirmed, agreed or likely. Do not hint at the answer.',
            '- Do not ask them to wait a specific number of minutes, and do not promise an outcome.',
            '- Do not mention managers\' rules, tools or internal systems beyond "уточню у менеджера".',
        ]);
    }

    public function failureInstruction(): string
    {
        return 'The price request could NOT be sent — the write failed. Do not tell the customer their price is '
            .'being checked, because nobody received it. Apologise briefly in one sentence, say you are having a '
            .'technical problem, and tell them a colleague will get back to them about the price.';
    }

    public function pendingFallback(Agent $agent): string
    {
        return $agent->response_format['haggle_pending_message']
            ?? 'Хорошо, я уточню у менеджера, дадут ли такую цену, и вернусь к вам.';
    }

    protected function phoneFromHistory(?Conversation $conversation): ?string
    {
        if ($conversation === null) {
            return null;
        }

        return $this->phones->findIn(
            $conversation->messages()->where('role', 'user')->orderByDesc('id')->take(30)->pluck('content')
        );
    }

    protected function money(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return $value > 0 ? (float) $value : null;
        }

        if (is_string($value)) {
            $normalized = str_replace([' ', ' ', ','], ['', '', '.'], trim($value));

            return is_numeric($normalized) && (float) $normalized > 0 ? (float) $normalized : null;
        }

        return null;
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
