<?php

namespace App\Services\Agents;

use App\Exceptions\LlmException;
use App\Models\Agent;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AgentPersonaAssistant
{
    public const QUESTIONS = ['priority', 'forbidden', 'phrases', 'angry', 'extra'];

    public const MAX_ANSWER_LENGTH = 600;

    protected const CONTEXT_CATEGORIES = 12;

    public function __construct(protected LlmProviderInterface $llm) {}

    public function draft(Agent $agent, array $answers): array
    {
        $result = $this->llm->generate(
            $this->systemPrompt(),
            [['role' => 'user', 'text' => $this->brief($agent, $answers)]],
            [

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
