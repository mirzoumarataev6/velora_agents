<?php

namespace App\Services;

use App\Exceptions\LlmException;
use App\Jobs\SendLeadNotificationJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Services\Llm\LlmProviderInterface;
use App\Services\Llm\UsageRecorder;
use App\Services\Rag\RetrievedChunk;
use App\Services\Rag\VectorStoreInterface;
use Illuminate\Support\Facades\Log;

/**
 * Turns a customer message into a grounded reply: embeds the question, retrieves
 * relevant knowledge chunks, builds the system prompt, calls the LLM provider, and persists
 * both sides of the exchange with retrieval/timing metadata.
 */
class AgentReplyService
{
    protected const LANGUAGE_NAMES = [
        'ru' => 'Russian',
        'en' => 'English',
        'tj' => 'Tajik',
        'uz' => 'Uzbek',
    ];

    /**
     * If the LLM provider is down (e.g. a quota exhaustion that won't clear for
     * hours), don't spam the customer with the same canned fallback text on
     * every message they send in the meantime.
     */
    protected const FALLBACK_SUPPRESSION_MINUTES = 5;

    /**
     * Matches a message that is ONLY a greeting (nothing else) so the
     * provider-down fallback path can reply with something friendlier than the
     * generic fallback text — still no API call, just a nicer canned reply.
     */
    protected const GREETING_PATTERN = '/^\s*(привет\w*|здравствуй\w*|добр(ый|ое)\s+(день|вечер|утро)|hi|hello+|hey+|salom\w*|ассалому\s*алейкум|салом\w*|assalomu\s*alaykum)[\s!.,?]*$/iu';

    public function __construct(
        protected LlmProviderInterface $llm,
        protected VectorStoreInterface $vectorStore,
        protected UsageRecorder $usage,
        protected LeadCaptureService $leadCapture,
        protected EscalationService $escalation,
    ) {}

    /**
     * Whether the last assistant reply in this conversation was a provider-down
     * fallback sent recently enough that a new reply attempt should be
     * skipped entirely — both to avoid spamming the customer with repeated
     * identical canned text, and to avoid a doomed API call when the failure
     * is a daily quota that won't clear within the window anyway.
     */
    public function shouldSuppressReply(Conversation $conversation): bool
    {
        $lastAssistant = $conversation->messages()
            ->where('role', 'assistant')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if (! $lastAssistant || ! ($lastAssistant->meta['failed'] ?? false)) {
            return false;
        }

        return $lastAssistant->created_at->gt(now()->subMinutes(self::FALLBACK_SUPPRESSION_MINUTES));
    }

    /**
     * Persist the customer's message and generate a full RAG-grounded reply for it.
     * Used by the Playground, which doesn't need the message pre-created for
     * idempotency (there's no external delivery to deduplicate).
     *
     * @return array{user: Message, assistant: Message}
     */
    public function reply(
        Conversation $conversation,
        string $userMessage,
        ?string $externalMessageId = null,
        string $sourceType = 'text',
        ?int $voiceDuration = null,
    ): array {
        $userMsg = $conversation->messages()->create([
            'role' => 'user',
            'content' => $userMessage,
            'external_message_id' => $externalMessageId,
            // A sandbox recording is stored exactly like a Telegram voice note:
            // the transcript is the content, and this is what says it was spoken.
            'source_type' => $sourceType,
            'voice_duration' => $voiceDuration,
        ]);

        return ['user' => $userMsg, 'assistant' => $this->generateReplyFor($conversation, $userMsg)];
    }

    /**
     * Generate and persist a full RAG-grounded reply for an ALREADY-persisted
     * user message. Callers that need dedup-safe message creation (e.g. a
     * channel webhook job, where the same delivery could be processed more
     * than once) should create the user message themselves first — guarded by
     * a unique constraint — and only call this once that succeeds.
     */
    public function generateReplyFor(Conversation $conversation, Message $userMsg): Message
    {
        $agent = $conversation->agent;
        $this->usage->for($agent);
        $startedAt = microtime(true);

        $retrieved = [];
        $embeddingMs = 0.0;
        $searchMs = 0.0;
        $generationMs = 0.0;
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];
        $failed = false;
        /** @var Lead[] $capturedLeads */
        $capturedLeads = [];
        $escalation = null;

        try {
            $embeddingStart = microtime(true);
            $queryEmbedding = $this->llm->embed($userMsg->content);
            $embeddingMs = (microtime(true) - $embeddingStart) * 1000;

            $searchStart = microtime(true);
            $retrieved = $this->vectorStore->search(
                $agent,
                $queryEmbedding['vector'],
                (int) config('agent.rag.top_k'),
                (float) config('agent.rag.similarity_threshold'),
                (float) config('agent.rag.relative_ratio'),
                (int) config('agent.rag.min_context_chunks'),
            );
            $searchMs = (microtime(true) - $searchStart) * 1000;

            $captureLeads = (bool) $agent->capture_leads;
            $detectEscalation = $this->escalation->enabledFor($agent);

            $systemPrompt = $this->buildSystemPrompt($agent, $retrieved, $conversation);
            if ($captureLeads) {
                $systemPrompt .= "\n\n".$this->leadCapture->systemInstruction();
            }
            if ($detectEscalation) {
                $systemPrompt .= "\n\n".$this->escalation->systemInstruction($agent);
            }
            $contents = $this->buildContents($conversation);

            Log::debug('agent.prompt', [
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'system_prompt' => $systemPrompt,
                'contents' => $contents,
            ]);

            $options = [
                'max_output_tokens' => $this->maxOutputTokens($agent),
                // Disabled: this is a latency-sensitive chat reply, not a reasoning task, and
                // thinking tokens are drawn from the same maxOutputTokens budget as the visible
                // reply — leaving thinking on risks silently truncating (or delaying) the answer.
                'thinking_budget' => 0,
            ];
            // Both tools ride on the one generation that was happening anyway —
            // the frustration check costs no extra request.
            $tools = [];
            if ($captureLeads) {
                $tools[] = $this->leadCapture->toolDefinition();
            }
            if ($detectEscalation) {
                $tools[] = $this->escalation->toolDefinition();
            }
            if ($tools !== []) {
                $options['tools'] = $tools;
            }

            $generationStart = microtime(true);
            $result = $this->llm->generate($systemPrompt, $contents, $options);

            if ($detectEscalation) {
                $escalation = $this->firstEscalationCall($agent, $result['tool_calls'] ?? []);
            }

            $leadCalls = $captureLeads
                ? array_values(array_filter(
                    $result['tool_calls'] ?? [],
                    fn ($call) => ($call['name'] ?? null) === LeadCaptureService::TOOL_NAME
                ))
                : [];

            if ($leadCalls !== []) {
                // The model asked to record a lead: persist it, then do one more
                // round-trip (tool result fed back) so the customer gets a natural
                // confirmation in the conversation's own language.
                $capture = $this->captureLeadsAndConfirm($agent, $conversation, $contents, $systemPrompt, $result, $leadCalls);
                $replyText = $capture['text'];
                $usage = $capture['usage'];
                $capturedLeads = $capture['leads'];
            } else {
                $replyText = trim($result['text']) !== ''
                    ? $result['text']
                    // Flagging must never turn into silence. If the model spent
                    // its turn on the tool call and wrote nothing, the customer
                    // still gets an acknowledgement rather than the generic
                    // "I don't know" fallback, which would read as a brush-off
                    // to someone who has just complained.
                    : ($escalation !== null ? $this->escalationAcknowledgement($agent) : $this->fallbackMessage($agent));
                $usage = ['input_tokens' => $result['input_tokens'], 'output_tokens' => $result['output_tokens']];
            }

            $generationMs = (microtime(true) - $generationStart) * 1000;
        } catch (LlmException $e) {
            Log::warning('Agent reply generation failed, using fallback message', [
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
            // A bare greeting doesn't need the LLM to answer well — when the provider is
            // already down, this reads as a normal reply instead of a second
            // "something's broken" signal stacked on top of a trivial message.
            $replyText = $this->isSimpleGreeting($userMsg->content)
                ? $this->greetingMessage($agent)
                : $this->fallbackMessage($agent);
            $failed = true;
        }

        $totalMs = (microtime(true) - $startedAt) * 1000;

        $assistantMsg = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $replyText,
            'meta' => [
                'retrieved_chunks' => array_map(fn ($r) => $r->toArray(), $retrieved),
                'input_tokens' => $usage['input_tokens'],
                'output_tokens' => $usage['output_tokens'],
                'embedding_ms' => round($embeddingMs, 1),
                'search_ms' => round($searchMs, 2),
                'retrieval_ms' => round($embeddingMs + $searchMs, 1),
                'generation_ms' => round($generationMs, 1),
                'total_ms' => round($totalMs, 1),
                'failed' => $failed,
                'lead_captured' => $capturedLeads !== [],
                'lead_ids' => array_map(fn (Lead $lead) => $lead->id, $capturedLeads),
                'escalated' => $escalation !== null,
                'escalation_signal' => $escalation['signal'] ?? null,
                'escalation_confidence' => $escalation['confidence'] ?? null,
            ],
        ]);

        $conversation->update(['last_message_at' => now()]);

        foreach ($capturedLeads as $lead) {
            SendLeadNotificationJob::dispatch($lead);
        }

        // After the reply is persisted: the customer gets this turn's answer,
        // and only the NEXT incoming message finds the conversation in human
        // hands. Flagging is not a gag order on the reply already written.
        if ($escalation !== null) {
            $this->escalation->escalate($agent, $conversation, $escalation);
        }

        return $assistantMsg;
    }

    /**
     * Persist every create_lead call the model made this turn, then feed the
     * tool results back for one more generation so the reply confirms the order
     * in the customer's language. Falls back to a templated confirmation if that
     * follow-up generation fails — the lead is already saved, so the customer
     * must still hear back.
     *
     * @param  array<int, array{role: string, text: string}>  $contents
     * @param  array<int, array{id: string, name: string, arguments: array}>  $leadCalls
     * @return array{text: string, usage: array{input_tokens: int, output_tokens: int}, leads: Lead[]}
     */
    protected function captureLeadsAndConfirm(
        Agent $agent,
        Conversation $conversation,
        array $contents,
        string $systemPrompt,
        array $firstResult,
        array $leadCalls,
    ): array {
        $source = $conversation->channel?->type ?? 'playground';
        $leads = [];
        $continuation = $contents;

        foreach ($leadCalls as $call) {
            $leads[] = $this->leadCapture->persist($agent, $conversation, $call['arguments'] ?? [], $source);

            $continuation[] = ['role' => 'tool_call', 'id' => $call['id'], 'name' => $call['name'], 'arguments' => $call['arguments'] ?? []];
            $continuation[] = ['role' => 'tool_result', 'id' => $call['id'], 'name' => $call['name'], 'result' => 'Lead saved. Confirm receipt to the customer.'];
        }

        $inputTokens = (int) ($firstResult['input_tokens'] ?? 0);
        $outputTokens = (int) ($firstResult['output_tokens'] ?? 0);

        try {
            $confirm = $this->llm->generate($systemPrompt, $continuation, [
                'max_output_tokens' => $this->maxOutputTokens($agent),
                'thinking_budget' => 0,
            ]);
            $inputTokens += (int) $confirm['input_tokens'];
            $outputTokens += (int) $confirm['output_tokens'];
            $text = trim($confirm['text']) !== '' ? $confirm['text'] : $this->leadCapture->confirmationFallback($agent);
        } catch (LlmException $e) {
            Log::warning('Lead confirmation generation failed, using templated confirmation', [
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
            // If the model already wrote text alongside the tool call, prefer it
            // over the static fallback.
            $text = trim($firstResult['text'] ?? '') !== ''
                ? $firstResult['text']
                : $this->leadCapture->confirmationFallback($agent);
        }

        return [
            'text' => $text,
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
            'leads' => $leads,
        ];
    }

    /**
     * A short, deterministic-in-spirit reply for when a photo couldn't be
     * confidently matched to a product: ask for the product name instead of
     * describing the image or apologizing at length. Skips retrieval entirely
     * — there's nothing useful to search for yet.
     */
    public function askForProductName(Conversation $conversation, Message $userMsg): Message
    {
        $agent = $conversation->agent;
        $this->usage->for($agent);
        $startedAt = microtime(true);

        $systemPrompt = implode("\n\n", array_filter([
            "You are {$agent->name}, a customer support assistant for this business.",
            $this->languageInstruction($agent, $conversation),
            'The customer sent a photo but it could not be confidently matched to a specific product. '
                .'Reply with exactly ONE short, friendly sentence asking them to tell you the product name '
                .'(or brand/model) they are asking about. Do not describe what is visible in the photo. '
                .'Do not apologize at length — at most a brief "sorry" is fine. Do not list products. No emojis unless the business tone calls for them.',
        ]));

        $failed = false;

        try {
            $result = $this->llm->generate($systemPrompt, $this->buildContents($conversation), [
                'max_output_tokens' => 128,
                'thinking_budget' => 0,
            ]);
            $replyText = trim($result['text']) !== '' ? $result['text'] : $this->fallbackMessage($agent);
            $usage = ['input_tokens' => $result['input_tokens'], 'output_tokens' => $result['output_tokens']];
        } catch (LlmException $e) {
            Log::warning('Photo clarification generation failed, using fallback message', [
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
            $replyText = $this->fallbackMessage($agent);
            $usage = ['input_tokens' => 0, 'output_tokens' => 0];
            $failed = true;
        }

        $assistantMsg = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $replyText,
            'meta' => [
                'retrieved_chunks' => [],
                'input_tokens' => $usage['input_tokens'],
                'output_tokens' => $usage['output_tokens'],
                'embedding_ms' => 0,
                'search_ms' => 0,
                'retrieval_ms' => 0,
                'generation_ms' => round((microtime(true) - $startedAt) * 1000, 1),
                'total_ms' => round((microtime(true) - $startedAt) * 1000, 1),
                'failed' => $failed,
                'unclear_photo' => true,
            ],
        ]);

        $conversation->update(['last_message_at' => now()]);

        return $assistantMsg;
    }

    /**
     * A short apology-and-next-step for a voice note we could not turn into
     * text — transcription is off for this agent, it failed, or the note was
     * too long.
     *
     * Skips retrieval entirely (there is no question to search for yet) but
     * still goes through the model so the customer is answered in the language
     * they were speaking, which a canned string could not do across ru/tg/en.
     * If even that generation fails, a templated line is used rather than
     * leaving a voice note unanswered.
     */
    public function explainVoiceProblem(Conversation $conversation, Message $userMsg, string $reason): Message
    {
        $agent = $conversation->agent;
        $this->usage->for($agent);
        $startedAt = microtime(true);

        $situation = match ($reason) {
            'voice_disabled' => 'This assistant cannot listen to voice messages at all.',
            'voice_too_long' => 'The voice message was too long for this assistant to process.',
            default => 'The voice message could not be understood — it may have been unclear or the audio failed to process.',
        };

        $systemPrompt = implode("\n\n", array_filter([
            "You are {$agent->name}, a customer support assistant for this business.",
            $this->languageInstruction($agent, $conversation),
            'The customer sent a voice message. '.$situation.' '
                .'Reply with ONE or TWO short, warm sentences: acknowledge briefly, and ask them to send their '
                .'question as text instead. Do not blame the customer. Do not mention transcription, audio '
                .'formats, file sizes, errors, settings, or any internal system. Do not apologize at length. '
                .'No emojis unless the business tone calls for them.',
        ]));

        $failed = false;

        try {
            $result = $this->llm->generate($systemPrompt, $this->buildContents($conversation), [
                'max_output_tokens' => 160,
                'thinking_budget' => 0,
            ]);
            $replyText = trim($result['text']) !== '' ? $result['text'] : $this->voiceFallbackMessage($agent);
            $usage = ['input_tokens' => $result['input_tokens'], 'output_tokens' => $result['output_tokens']];
        } catch (LlmException $e) {
            Log::warning('Voice-problem reply generation failed, using fallback message', [
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
            $replyText = $this->voiceFallbackMessage($agent);
            $usage = ['input_tokens' => 0, 'output_tokens' => 0];
            $failed = true;
        }

        $assistantMsg = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $replyText,
            'meta' => [
                'retrieved_chunks' => [],
                'input_tokens' => $usage['input_tokens'],
                'output_tokens' => $usage['output_tokens'],
                'embedding_ms' => 0,
                'search_ms' => 0,
                'retrieval_ms' => 0,
                'generation_ms' => round((microtime(true) - $startedAt) * 1000, 1),
                'total_ms' => round((microtime(true) - $startedAt) * 1000, 1),
                'failed' => $failed,
                'voice_problem' => $reason,
            ],
        ]);

        $conversation->update(['last_message_at' => now()]);

        return $assistantMsg;
    }

    /**
     * Last resort when the provider is down too. Deliberately not the generic
     * fallback text, which says nothing about what the customer should do next.
     */
    protected function voiceFallbackMessage(Agent $agent): string
    {
        return $agent->response_format['voice_fallback_message']
            ?? 'Извините, я не смог разобрать голосовое сообщение. Напишите, пожалуйста, текстом.';
    }

    /**
     * The escalation tool call that clears this agent's confidence bar, if any.
     *
     * A call the model made but that falls short of the bar is deliberately
     * dropped here rather than downstream — below the bar it is not a signal,
     * it is a guess, and acting on guesses is what makes an alert worthless.
     *
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<string, mixed>|null
     */
    protected function firstEscalationCall(Agent $agent, array $toolCalls): ?array
    {
        foreach ($toolCalls as $call) {
            if (($call['name'] ?? null) !== EscalationService::TOOL_NAME) {
                continue;
            }

            $args = $call['arguments'] ?? [];

            if ($this->escalation->shouldEscalate($agent, $args)) {
                return $args;
            }

            Log::info('Escalation signal below threshold, ignored', [
                'agent_id' => $agent->id,
                'signal' => $args['signal'] ?? null,
                'confidence' => $args['confidence'] ?? null,
                'sensitivity' => $this->escalation->sensitivityFor($agent),
            ]);
        }

        return null;
    }

    /**
     * Used only when the model flagged the customer but wrote no reply text.
     */
    protected function escalationAcknowledgement(Agent $agent): string
    {
        return $agent->response_format['escalation_message']
            ?? 'Извините за неудобства. Я передал(а) ваш вопрос коллеге — с вами скоро свяжется человек.';
    }

    protected function fallbackMessage(Agent $agent): string
    {
        return $agent->response_format['fallback_message']
            ?? 'Извините, сейчас не получается ответить. Мы уточним этот вопрос и вернёмся с ответом.';
    }

    protected function greetingMessage(Agent $agent): string
    {
        return $agent->response_format['greeting_message']
            ?? 'Здравствуйте! Чем можем помочь?';
    }

    protected function isSimpleGreeting(string $text): bool
    {
        return (bool) preg_match(self::GREETING_PATTERN, trim($text));
    }

    protected function maxOutputTokens(Agent $agent): int
    {
        $maxLength = $agent->response_format['max_length'] ?? 600;

        // Rough chars-to-tokens conversion consistent with the provider's estimateTokens(),
        // with headroom since generation length isn't a hard character cap. Safe now that
        // thinking is disabled (see 'thinking_budget' => 0 above) — the full budget goes
        // to the visible reply instead of being shared with reasoning tokens.
        return max(128, (int) ceil($maxLength / 3));
    }

    /**
     * The ordered turns to send to Gemini: the last N messages of the
     * conversation, INCLUDING the most recent one — callers persist the new
     * user message before calling this, so it naturally appears as the final
     * turn without needing to be appended separately.
     *
     * @return array<int, array{role: string, text: string}>
     */
    protected function buildContents(Conversation $conversation): array
    {
        $limit = (int) config('agent.rag.history_length');

        return $conversation->messages()
            ->whereIn('role', ['user', 'assistant', 'operator'])
            ->orderByDesc('created_at')
            ->orderByDesc('id') // tiebreaker: created_at has only second precision, id reflects true insertion order
            ->take($limit)
            ->get()
            ->reverse()
            ->map(fn (Message $m) => [
                'role' => $m->role === 'user' ? 'user' : 'model',
                'text' => $m->content,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  RetrievedChunk[]  $retrieved
     */
    protected function buildSystemPrompt(Agent $agent, array $retrieved, Conversation $conversation): string
    {
        $format = $agent->response_format ?? [];
        $behavior = $agent->behavior ?? [];

        $parts = [];

        $parts[] = trim(sprintf(
            'You are %s, an AI sales assistant for this business%s.',
            $agent->name,
            $agent->description ? ": {$agent->description}" : ''
        ));

        // The business owner's own instructions take priority over the default tone/format
        // rules below whenever the two disagree — surfaced early and unmistakably for that reason.
        if (! empty($agent->custom_system_prompt)) {
            $parts[] = 'IMPORTANT — the business owner gave these instructions about personality and style. '.
                "They take priority over the default tone/formatting guidance elsewhere in this prompt whenever they conflict:\n"
                .$agent->custom_system_prompt;
        }

        // Kept as its own section rather than folded into the persona above:
        // that one says who the agent IS, this one says what it must DO in
        // named situations. Merging them would let a style note ("be brief")
        // dilute a hard rule ("never quote wholesale prices"). Omitted whole
        // when empty, so no bare header ever reaches the model.
        if (! empty($agent->rules_instructions)) {
            $parts[] = 'IMPORTANT — the business owner gave these situational rules for how to handle specific '.
                'cases. Follow them exactly. They take priority over the default tone/formatting guidance '.
                "elsewhere in this prompt, and over your own judgement about what to say, whenever they conflict:\n"
                .$agent->rules_instructions;
        }

        $parts[] = "Default tone (used except where the owner's instructions above say otherwise): {$agent->tone}.";

        $parts[] = $this->languageInstruction($agent, $conversation);

        if (! empty($format['max_length'])) {
            $parts[] = "Keep replies under approximately {$format['max_length']} characters.";
        }

        $parts[] = ! empty($format['use_emojis'])
            ? 'You may use emojis sparingly to sound warm and approachable.'
            : 'Do not use emojis.';

        $parts[] = $this->answeringPolicyInstruction();

        $parts[] = implode("\n", [
            'Rules:',
            '- This is a messenger chat, not an email: write like a helpful human texting back, not a brochure.',
            '- Keep replies short: 2-4 sentences for a typical question. Only go longer if the customer explicitly asks for a full list or detailed comparison.',
            '- Do not use bullet points, numbered lists, or markdown formatting unless the customer explicitly asked for a list.',
            '- Suggest at most ONE product per reply, and only items that actually appear in the KNOWLEDGE BASE.',
            '- End with at most ONE question, and only if it genuinely helps move the conversation forward — many replies need no question at all.',
            '- Do not decorate people or products with qualities you were not given. Words like "experienced",',
            '  "qualified", "talented", "leading", "highly rated" are claims about a real business and a real',
            '  person: use them only if the KNOWLEDGE BASE says so. Naming someone and their subject is a',
            '  complete answer — it does not need a compliment attached to sound good.',
        ]);

        $parts[] = 'Never reply with a bare refusal. "I have no information about that" is not an acceptable reply on its own — '
            .'it must always be followed by something useful: the general answer, the nearest thing you do have, or an offer to '
            .'put the customer in touch with a person.';

        $parts[] = $this->proactivityInstruction($agent);

        $parts[] = $this->fallbackInstruction($behavior['on_not_found'] ?? 'apologize', $format['fallback_message'] ?? null);

        $parts[] = $this->knowledgeBaseSection($retrieved);

        return implode("\n\n", $parts);
    }

    /**
     * The two-tier answering policy.
     *
     * The problem it solves: a strictly grounded assistant refuses roughly half
     * of what customers actually ask, because half of it is general curiosity
     * about the field rather than a question about the business ("what even is
     * backend?", "is Python hard?"). Refusing those reads as a broken product,
     * and answering them needs no knowledge base — the model already knows.
     *
     * So the two are separated. General knowledge of the FIELD is free to use;
     * facts about THIS BUSINESS remain strictly grounded. What counts as "the
     * field" is derived by the model from the agent's own description and
     * knowledge base, so an education centre's assistant is knowledgeable about
     * learning and programming while a clothing shop's is knowledgeable about
     * fabrics and sizing, with nothing about either hardcoded here.
     */
    protected function answeringPolicyInstruction(): string
    {
        return implode("\n", [
            'HOW TO ANSWER — there are two kinds of question and they are handled differently.',
            'Work out which kind you are being asked before you reply. Infer what field this business',
            'works in from your description above and from the KNOWLEDGE BASE below.',
            '',
            'TIER 1 — GENERAL questions about that field, asked out of curiosity rather than about this',
            'business specifically (what a concept means, how two things differ, whether something is',
            'hard to learn, why it matters, what a beginner should start with).',
            '- ANSWER THESE. Use your own general knowledge. You are the knowledgeable person at this',
            '  business, and explaining your field is part of the job — not a fact you need permission for.',
            '- Give a genuinely useful, accurate, plain-language answer, in the same 2-4 sentence chat style.',
            '- Then connect it back to the business in one short line, using only things that appear in the',
            '  KNOWLEDGE BASE — for example noting that a relevant course/product exists and offering to say',
            '  more. If the KNOWLEDGE BASE holds nothing relevant, just give the general answer and stop.',
            '  Skip this connecting line entirely if Proactivity below is OFF.',
            '- Keep the general part general. Never attach invented specifics (prices, dates, staff,',
            '  guarantees) to it, and never present your general knowledge as a statement about this business.',
            '',
            'TIER 2 — SPECIFIC FACTS ABOUT THIS BUSINESS: prices, discounts, who the staff/teachers are and',
            'their background, schedules and timetables, which products or courses exist, availability and',
            'stock, locations, contact details, policies, deadlines, guarantees.',
            '- These may come ONLY from the KNOWLEDGE BASE. Never invent, estimate, or infer them, and never',
            '  fill a gap with what is typical elsewhere. A plausible-sounding wrong price is worse than no price.',
            '- If the fact IS in the KNOWLEDGE BASE, answer it directly and specifically. Material that arrived',
            '  under a heading matching the question (staff, prices, schedule) is the answer — use it, and do',
            '  not claim you lack information that is sitting in front of you.',
            '- Report what the KNOWLEDGE BASE actually says and stop there. Do not pad a sparse entry with',
            '  flattering filler: if it gives a name and a subject, give the name and the subject: say nothing',
            '  about their experience, qualifications, awards, years in the industry or character unless it is',
            '  written there. Inventing a compliment about a real person is inventing a business fact.',
            '- If it is genuinely NOT there, do not stop at that. Say briefly that you cannot confirm it, then',
            '  do ONE of: give the closest related thing that IS in the KNOWLEDGE BASE; offer to pass the',
            '  question to a colleague; or offer to take their contact so someone can come back with it.',
        ]);
    }

    /**
     * @param  RetrievedChunk[]  $retrieved
     */
    protected function knowledgeBaseSection(array $retrieved): string
    {
        if ($retrieved === []) {
            return 'KNOWLEDGE BASE: (empty — nothing has been uploaded for this business yet). '
                .'You can still answer Tier 1 general questions about the field from your own knowledge. '
                .'For anything specific to this business, say you cannot confirm it and offer to connect the customer with a colleague.';
        }

        $confident = array_filter($retrieved, fn (RetrievedChunk $r) => $r->confident);
        $weak = array_filter($retrieved, fn (RetrievedChunk $r) => ! $r->confident);

        $render = fn (array $chunks) => collect($chunks)->map(
            fn (RetrievedChunk $r) => '['.($r->chunk->knowledgeSource?->name ?? 'Knowledge base')."]\n{$r->chunk->content}"
        )->implode("\n\n---\n\n");

        $section = 'KNOWLEDGE BASE:';

        if ($confident !== []) {
            $section .= "\n".$render($confident);
        }

        // Retrieval scored these poorly but had nothing better. Saying so lets the
        // model use them when they happen to fit and ignore them when they don't,
        // which is a better outcome than withholding them and reporting an empty
        // knowledge base — the failure this whole section exists to prevent.
        if ($weak !== []) {
            $section .= ($confident !== [] ? "\n\n" : "\n")
                ."POSSIBLY RELATED (weak match — the search found nothing clearly on-topic, so these may or\n"
                ."may not answer the question). Use them only if they genuinely do; if they do not, treat this\n"
                ."as a fact you don't have and pivot per TIER 2 instead of forcing them into an answer:\n"
                .$render($weak);
        }

        return $section;
    }

    /**
     * How forward-leaning the assistant should be about suggesting a next step.
     * Every level keeps the same hard guardrails — one suggestion max, one
     * question max, catalog-only, never pushy — and only shifts how much
     * initiative the assistant takes.
     */
    protected function proactivityInstruction(Agent $agent): string
    {
        return match ($agent->proactivity) {
            'off' => implode("\n", [
                'Proactivity: OFF.',
                '- Answer only what the customer asked. Do not upsell, cross-sell, or suggest additional products.',
                '- Only ask a follow-up question when it is strictly required to answer their request.',
            ]),
            'active' => implode("\n", [
                'Proactivity: ACTIVE.',
                '- After answering, take initiative to move the sale forward: offer ONE relevant next step — either a single related item FROM THE KNOWLEDGE BASE, or an offer to place the order / book a time.',
                '- Never invent products, prices, or promotions; only suggest items that appear in the KNOWLEDGE BASE.',
                '- Still at most one suggestion and one question. Stay warm and helpful — never pushy, and never repeat an offer the customer already turned down.',
            ]),
            default => implode("\n", [ // subtle
                'Proactivity: SUBTLE.',
                '- After answering, you MAY add at most one short, genuinely helpful suggestion or one clarifying question — but only when it clearly helps the customer.',
                '- Any product you mention must come FROM THE KNOWLEDGE BASE. Never invent items and never push a sale.',
                '- If a plain answer is enough, just answer — do not force a suggestion.',
            ]),
        };
    }

    protected function languageInstruction(Agent $agent, Conversation $conversation): string
    {
        if ($agent->language !== 'auto' && ! empty($agent->language)) {
            return 'Always reply in '.(self::LANGUAGE_NAMES[$agent->language] ?? $agent->language)
                .', regardless of what language the customer writes in, unless they explicitly ask for another language.';
        }

        $hasPriorReply = $conversation->messages()->where('role', 'assistant')->exists();

        if (! $hasPriorReply) {
            return 'Reply in the same language the customer used in their latest message. '
                ."If their message has no language signal (e.g. it's just a photo or an emoji), default to Russian.";
        }

        return 'Reply in the same language the customer used in their latest message. '
            .'If their latest message has no clear language of its own (e.g. a photo with no caption, "?", emoji-only), '
            .'keep using whatever language your own earlier replies in this conversation used — do not switch languages '
            .'without a clear signal from the customer.';
    }

    /**
     * How to pivot when a TIER 2 business fact is missing. This picks the shape
     * of the pivot; it never licenses a bare refusal, and it does not apply to
     * TIER 1 general questions — those are answered, not deflected.
     */
    protected function fallbackInstruction(string $onNotFound, ?string $fallbackMessage): string
    {
        $instruction = 'When a TIER 2 fact about this business is missing from the KNOWLEDGE BASE, do not guess, '
            .'and do not end the conversation there. ';

        $instruction .= match ($onNotFound) {
            'human_handoff' => 'Say briefly that you want to confirm it rather than risk being wrong, and tell the customer a colleague will follow up shortly. Offer to take their contact if you do not already have it.',
            'suggest_alternatives' => 'Say briefly that you cannot confirm that one, then offer the closest relevant things that ARE in the KNOWLEDGE BASE (at most 2) and ask if either helps.',
            default => "Say briefly that you don't have that detail to hand, then immediately offer a next step: the nearest related thing you do have from the KNOWLEDGE BASE, or to check with a colleague and come back.",
        };

        if (! empty($fallbackMessage)) {
            $instruction .= " You may phrase it close to: \"{$fallbackMessage}\"";
        }

        return $instruction;
    }
}
