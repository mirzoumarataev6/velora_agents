<?php

namespace App\Services\Agents;

use App\Exceptions\LlmException;
use App\Models\Agent;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Drafts the two free-text fields on the agent form — the situational rules and
 * the persona — from a handful of questions the owner answers about their own
 * business.
 *
 * Deliberately nothing to do with AgentReplyService. That class is the
 * customer-facing pipeline: it is grounded, tool-armed, latency-sensitive and
 * carefully fenced about what it may claim. This one talks to the OWNER, once,
 * about how their assistant should behave. Sharing a prompt between the two
 * would mean every guard written for a customer conversation ("never invent a
 * product", "never promise a call") applying to a copywriting task where they
 * mean nothing — and, worse, the reverse.
 *
 * What comes back is a DRAFT. It is handed to the form for the owner to read and
 * edit; nothing here writes to the agent.
 */
class AgentPersonaAssistant
{
    /**
     * The questions asked, in order. Kept as data rather than markup so the
     * wizard, the prompt and the validation all read from one list — a question
     * added here appears in the UI and reaches the model without a second edit.
     *
     * `key` is what the translation files and the request payload use.
     */
    public const QUESTIONS = ['priority', 'forbidden', 'phrases', 'angry', 'extra'];

    /** Longest answer accepted per question — a paragraph, not an essay. */
    public const MAX_ANSWER_LENGTH = 600;

    /** How many catalog categories are worth naming as context. */
    protected const CONTEXT_CATEGORIES = 12;

    public function __construct(protected LlmProviderInterface $llm) {}

    /**
     * Produce both blocks for this agent.
     *
     * @param  array<string, string>  $answers  Keyed by QUESTIONS.
     * @return array{rules: string, persona: string, input_tokens: int, output_tokens: int}
     *
     * @throws LlmException when the provider fails or returns nothing usable.
     */
    public function draft(Agent $agent, array $answers): array
    {
        $result = $this->llm->generate(
            $this->systemPrompt(),
            [['role' => 'user', 'text' => $this->brief($agent, $answers)]],
            [
                // Long enough for two substantial blocks; this is a one-off
                // authoring task, not a chat turn, so the budget is generous.
                'max_output_tokens' => 2000,
                'thinking_budget' => 0,
            ],
        );

        $parsed = $this->parse($result['text'] ?? '');

        if ($parsed === null) {
            Log::warning('Persona assistant returned an unparseable draft', [
                'agent_id' => $agent->id,
                'finish_reason' => $result['finish_reason'] ?? null,
                'length' => mb_strlen((string) ($result['text'] ?? '')),
            ]);

            throw new LlmException('The assistant did not return both sections.');
        }

        return $parsed + [
            'input_tokens' => (int) ($result['input_tokens'] ?? 0),
            'output_tokens' => (int) ($result['output_tokens'] ?? 0),
        ];
    }

    /**
     * The system prompt.
     *
     * Two things it works hard at. First, the output contract: the response has
     * to be split reliably into two fields, and asking for JSON from a model
     * writing multi-paragraph Russian invites escaping bugs — so it asks for two
     * named markers instead, which survive any amount of prose between them.
     *
     * Second, the register. The rules field wants "if X → do Y" lines, matching
     * the placeholder the owner can see in the form; the persona field wants
     * prose addressed to a new employee, matching its own guidance. A model left
     * to its own devices writes the same bulleted marketing copy into both.
     */
    public function systemPrompt(): string
    {
        return implode("\n", [
            'You help a small business owner configure their AI sales assistant. The owner has answered a few '
                .'questions about how they want it to behave. Turn their answers into two configuration blocks.',
            '',
            'OUTPUT FORMAT — exactly this, nothing before or after:',
            '===RULES===',
            '(the situational rules block)',
            '===PERSONA===',
            '(the persona and style block)',
            '',
            'THE RULES BLOCK:',
            '- Short bullet lines in the shape "если клиент <ситуация> — <что сделать>". One situation per line.',
            '- Concrete and checkable. "Будь полезным" is not a rule; "если клиент спрашивает про доставку — '
                .'уточни город и назови срок" is.',
            '- Cover what the owner actually told you: their priorities, the topics they forbid, how to handle an '
                .'angry customer, and any business-specific rule they gave. Do not pad with generic retail advice '
                .'they did not ask for.',
            '- 6 to 12 lines. Fewer is better than filler.',
            '',
            'THE PERSONA BLOCK:',
            '- Prose, not bullets. Written as instructions to a new employee on their first day: who they are, how '
                .'they speak to customers, what to sound like and what to avoid.',
            '- 3 to 6 sentences. Include any phrases the owner said they always use, quoted as their own words.',
            '- Describe tone and manner only. Do NOT restate the situational rules here — the other block owns those.',
            '',
            'ALWAYS:',
            '- Write in Russian, in the second person singular ("отвечай", "уточни"), unless the owner\'s own '
                .'answers are clearly in another language — then match theirs.',
            '- Use ONLY what the owner told you and the business context given. Never invent products, prices, '
                .'delivery terms, opening hours, guarantees or staff. If they did not mention something, leave it out.',
            '- Never mention that you are an AI, and never address the owner. These blocks are configuration text, '
                .'not a letter.',
        ]);
    }

    /**
     * The brief: everything already known about the business, then the owner's
     * answers.
     *
     * The context comes first and is labelled as context, because its job is to
     * make the draft specific — a rules block for "Ноутбуки и аксессуары" should
     * mention warranties and configurations, and one for a flower shop should
     * not. The answers come last because recency is where models weight
     * instructions hardest.
     *
     * @param  array<string, string>  $answers
     */
    public function brief(Agent $agent, array $answers): string
    {
        $lines = ['BUSINESS CONTEXT (facts already on file — use them, do not repeat them back verbatim):'];

        $lines[] = '- Name: '.$agent->name;

        if (filled($agent->description)) {
            $lines[] = '- What it does: '.Str::limit($agent->description, 500);
        }

        if (filled($agent->tone)) {
            $lines[] = '- Tone preset already chosen: '.$agent->tone;
        }

        $categories = $this->catalogCategories($agent);

        if ($categories !== []) {
            $lines[] = '- Sells (catalog categories): '.implode(', ', $categories);
        }

        $productCount = $agent->datasetProducts()->count();

        if ($productCount > 0) {
            $lines[] = "- Catalog size: {$productCount} products";
        }

        $sources = $agent->knowledgeSources()->where('status', 'ready')->pluck('name')->take(8)->all();

        if ($sources !== []) {
            $lines[] = '- Knowledge base covers: '.implode(', ', $sources);
        }

        if ($agent->hagglesOnPrice()) {
            // Worth stating, because otherwise the model writes discount rules
            // that contradict the negotiation feature's own prompt.
            $lines[] = '- This assistant negotiates on price, and escalates below-floor offers to the owner. '
                .'Do NOT write rules about granting discounts.';
        }

        $lines[] = '';
        $lines[] = "THE OWNER'S ANSWERS:";

        foreach (self::QUESTIONS as $key) {
            $answer = trim((string) ($answers[$key] ?? ''));

            if ($answer === '') {
                continue;
            }

            $lines[] = '- '.$this->questionLabel($key).': '.Str::limit($answer, self::MAX_ANSWER_LENGTH);
        }

        return implode("\n", $lines);
    }

    /**
     * English labels for the model, deliberately not the Russian ones the owner
     * saw: the question text in the UI is written to be answerable by a shop
     * owner, while these are written to be unambiguous to a model.
     */
    protected function questionLabel(string $key): string
    {
        return match ($key) {
            'priority' => 'What matters most in a reply (speed / warmth / precision / closing the sale)',
            'forbidden' => 'Topics the assistant must never discuss',
            'phrases' => 'Phrases and wording the owner always uses',
            'angry' => 'How to handle an angry or dissatisfied customer',
            default => 'Other business-specific rules',
        };
    }

    /**
     * @return array<int, string>
     */
    protected function catalogCategories(Agent $agent): array
    {
        return $agent->datasetProducts()
            ->whereNotNull('category')
            ->distinct()
            ->limit(self::CONTEXT_CATEGORIES)
            ->pluck('category')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Split the two blocks apart.
     *
     * Both must be present and non-empty. A response carrying only one of them
     * is a failure rather than a partial success: half a draft silently
     * overwriting one textarea and leaving the other untouched is worse for the
     * owner than an honest error they can retry.
     *
     * @return array{rules: string, persona: string}|null
     */
    protected function parse(string $text): ?array
    {
        if (! preg_match('/===RULES===(.*?)===PERSONA===(.*)$/su', $text, $matches)) {
            return null;
        }

        $rules = trim($matches[1]);
        $persona = trim($matches[2]);

        if ($rules === '' || $persona === '') {
            return null;
        }

        return ['rules' => $rules, 'persona' => $persona];
    }
}
