<?php

namespace App\Services;

use App\Exceptions\LlmException;
use App\Jobs\SendLeadNotificationJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Services\Dataset\ProductSearchService;
use App\Services\Escalation\HostilityDetector;
use App\Services\Haggling\HagglingService;
use App\Services\Leads\OrderConfirmationGuard;
use App\Services\Llm\LlmProviderInterface;
use App\Services\Llm\UsageRecorder;
use App\Services\Quality\NonAnswerDetector;
use App\Services\Rag\ProductQuestionDetector;
use App\Services\Rag\RetrievedChunk;
use App\Services\Rag\VectorStoreInterface;
use App\Services\Reply\CustomerLocale;
use App\Services\Reply\FollowUpDetector;
use App\Services\Reply\LanguageDetector;
use App\Services\Reply\ReplySimilarity;
use App\Services\Reply\ReplyStyleCleaner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

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

    /**
     * How many times the model may call search_products before the reply is
     * written anyway. One refinement after a miss is genuinely useful ("no
     * jackets under 500" -> widen the budget); a third is the model looping.
     */
    protected const MAX_PRODUCT_SEARCH_ROUNDS = 2;

    /**
     * Characters of output budgeted per token when sizing the generation cap.
     *
     * One, not the three or four a latin-calibrated estimate would use. The
     * agent's languages are Russian and Tajik, and Cyrillic costs the tokenizer
     * roughly one token per character — a rare word can cost more. A cap sized
     * for latin cut a 600-character reply off at around 400, in the middle of a
     * word ("Если вас интересует какая-то определ"), and the customer saw the
     * fragment. The character limit is a STYLE instruction the model follows;
     * this cap is only the safety net behind it, so it is sized to sit above
     * what the prompt asks for rather than to enforce it.
     */
    protected const OUTPUT_CHARS_PER_TOKEN = 1.0;

    /**
     * Extra room on top, for the sentence the model is finishing when it reaches
     * the limit. Truncation is worse than length.
     */
    protected const OUTPUT_TOKEN_HEADROOM = 1.25;

    /** Whatever the owner sets, one chat reply is never bigger than this. */
    protected const MAX_OUTPUT_TOKENS = 4000;

    /**
     * How many of the customer's own recent messages are checked for the same
     * question being asked again. Three strikes — asked, re-asked, asked a third
     * time — is a conversation that is not going to resolve itself.
     */
    protected const REPEATED_QUESTION_LOOKBACK = 6;

    /**
     * How far back to look for the language the customer is writing in. Enough
     * to ride out a run of "ок"/photo turns without reaching back into a part of
     * the conversation they have already moved on from.
     */
    protected const LANGUAGE_LOOKBACK = 5;

    /**
     * How recently an order must have been recorded for a fresh confirmation to
     * be taken as referring to it. A customer saying "оформите ещё раз" a minute
     * later means the same order, not a second one.
     */
    protected const ORDER_CLAIM_WINDOW_MINUTES = 10;

    /** How many of the assistant's own recent replies a new one must not repeat. */
    protected const REPEAT_LOOKBACK = 3;

    /** "/start", "/start@SomeBot", "/start <deep-link payload>". */
    protected const START_COMMAND_PATTERN = '/^\/start(?:@\S+)?(?:\s.*)?$/su';

    public function __construct(
        protected LlmProviderInterface $llm,
        protected VectorStoreInterface $vectorStore,
        protected UsageRecorder $usage,
        protected LeadCaptureService $leadCapture,
        protected EscalationService $escalation,
        protected ProductSearchService $productSearch,
        protected ProductQuestionDetector $productQuestions,
        protected FollowUpDetector $followUps,
        protected LanguageDetector $languages,
        protected ReplySimilarity $similarity,
        protected HostilityDetector $hostility,
        protected OrderConfirmationGuard $orderClaims,
        protected HagglingService $haggling,
        // Stateless and dependency-free, so it is defaulted rather than bound:
        // nothing ever needs to swap it, and the backfill command constructs
        // its own copy of the same class.
        protected NonAnswerDetector $nonAnswers = new NonAnswerDetector,
        protected CustomerLocale $locales = new CustomerLocale,
        protected ReplyStyleCleaner $styleCleaner = new ReplyStyleCleaner,
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
        /** @var Lead[] $haggleLeads Pending price approvals raised this turn. */
        $haggleLeads = [];
        $escalation = null;
        $productSearches = 0;
        $productQueries = [];
        $productImageCandidates = [];
        $productMisses = 0;
        $retrievalSkipped = false;
        $handover = null;
        $askedForPhone = false;
        $isFollowUp = false;
        $rewroteRepeat = false;
        $styleCleaned = false;

        try {
            $detectEscalation = $this->escalation->enabledFor($agent);
            $searchProducts = $this->productSearch->enabledFor($agent);
            // Negotiating needs something to negotiate over: with no catalog
            // there is no listed price, and the floor is a percentage of one.
            $negotiatesPrice = $searchProducts && $this->haggling->enabledFor($agent);

            // The reply the customer is answering. Held here rather than looked
            // up later because two separate decisions need it: how to prompt for
            // a short follow-up, and whether what comes back is that same reply
            // sent again.
            $previousReply = $this->lastAssistantMessage($conversation, $userMsg);
            $isFollowUp = $this->followUps->isShortFollowUp($userMsg->content, $previousReply !== null);

            // Detected here, from the text, and not left to the model: a
            // customer swearing at the assistant is a fact about their message,
            // and the model — prompted to be warm and de-escalating — reads it
            // as something to apologise for rather than as a reason to fetch a
            // person. See HostilityDetector.
            $handover = $detectEscalation ? $this->detectHandover($conversation, $userMsg) : null;

            // "How much is the Lenovo?" is answered entirely by search_products;
            // embedding it and scanning the knowledge base adds a round trip and
            // a full cosine pass to produce chunks about delivery policy. Skipped
            // only when the message is unambiguously a product question — see
            // ProductQuestionDetector, which is built to say no when unsure.
            $retrievalSkipped = $searchProducts
                && config('agent.rag.skip_for_product_questions')
                && $this->productQuestions->isPureProductQuestion($userMsg->content);

            if (! $retrievalSkipped) {
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
            }

            $systemPrompt = $this->buildSystemPrompt($agent, $retrieved, $conversation, $searchProducts, $retrievalSkipped);

            if ($isFollowUp) {
                $systemPrompt .= "\n\n".$this->followUpInstruction($previousReply?->content);
            }

            if ($handover !== null) {
                $systemPrompt .= "\n\n".$this->handoverInstruction();
            }
            if ($searchProducts) {
                $systemPrompt .= "\n\n".$this->productSearch->systemInstruction();
            }
            // Unconditional: recording what a customer wants to buy is the
            // whole job, and an agent that cannot take an order is not a
            // cheaper agent — it is a broken one.
            $systemPrompt .= "\n\n".$this->leadCapture->systemInstruction();
            if ($negotiatesPrice) {
                $systemPrompt .= "\n\n".$this->haggling->systemInstruction($agent);
            }
            if ($detectEscalation) {
                $systemPrompt .= "\n\n".$this->escalation->systemInstruction($agent);
            }
            // Keep the writing guidance after the tool instructions: their
            // ordering examples must not become a sales invitation every turn.
            $systemPrompt .= "\n\n".$this->conversationStyleInstruction();
            $contents = $this->buildContents($conversation);

            Log::debug('agent.prompt', [
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'system_prompt' => $systemPrompt,
                'contents' => $contents,
            ]);

            $options = [
                'max_output_tokens' => $this->maxOutputTokens($agent),
                'temperature' => 0.4,
                // Disabled: this is a latency-sensitive chat reply, not a reasoning task, and
                // thinking tokens are drawn from the same maxOutputTokens budget as the visible
                // reply — leaving thinking on risks silently truncating (or delaying) the answer.
                'thinking_budget' => 0,
            ];
            // Both tools ride on the one generation that was happening anyway —
            // the frustration check costs no extra request.
            $tools = [];
            if ($searchProducts) {
                $tools[] = $this->productSearch->toolDefinition();
            }
            $tools[] = $this->leadCapture->toolDefinition();
            if ($negotiatesPrice) {
                $tools[] = $this->haggling->toolDefinition();
            }
            if ($detectEscalation) {
                $tools[] = $this->escalation->toolDefinition();
            }
            if ($tools !== []) {
                $options['tools'] = $tools;
            }

            $generationStart = microtime(true);
            $result = $this->llm->generate($systemPrompt, $contents, $options);
            $toolCalls = $result['tool_calls'] ?? [];
            $carried = ['input_tokens' => 0, 'output_tokens' => 0];

            // A catalog lookup is answered here and fed back, so the reply the
            // customer sees is written with the real prices in hand. $contents
            // grows with the exchange, which is what keeps a follow-up round
            // (and a later lead confirmation) coherent.
            if ($searchProducts) {
                $resolved = $this->resolveProductSearches($agent, $systemPrompt, $contents, $result, $options);
                $result = $resolved['result'];
                $contents = $resolved['contents'];
                $carried = $resolved['carried'];
                $productSearches = $resolved['searches'];
                $productQueries = $resolved['queries'];
                $productImageCandidates = $resolved['images'];
                $productMisses = $resolved['misses'];
                // Replaced, not appended: $resolved carries forward the non-search
                // calls from every superseded generation (the first one included),
                // and $result is now the final generation. Adding the original
                // list back would record the same lead twice.
                $toolCalls = [...$resolved['tool_calls'], ...($result['tool_calls'] ?? [])];
            }

            // The catalog answers a product question only if the model actually
            // asks it. A model that answers one out of its own head — the exact
            // failure behind "we don't have that" for a product sitting in the
            // catalog — leaves no other trace, so it is recorded here with the
            // message that produced it.
            if ($searchProducts && $retrievalSkipped && $productSearches === 0) {
                Log::warning('Product question answered without calling '.ProductSearchService::TOOL_NAME, [
                    'agent_id' => $agent->id,
                    'conversation_id' => $conversation->id,
                    'message' => $userMsg->content,
                    'other_tool_calls' => array_values(array_filter(array_map(
                        fn ($call) => $call['name'] ?? null,
                        $toolCalls,
                    ))),
                ]);
            }

            if ($detectEscalation) {
                $escalation = $this->firstEscalationCall($agent, $toolCalls);
            }

            // The detector's verdict stands on its own. If the model also
            // flagged this turn, its summary is the better one to keep — it saw
            // the conversation; the detector only saw the words.
            if ($handover !== null) {
                $escalation = [
                    'signal' => $handover['signal'],
                    'confidence' => 1.0,
                    'summary' => $escalation['summary'] ?? $handover['summary'],
                    'detected_by' => 'pipeline',
                    'reason' => $handover['reason'],
                ];
            }

            $leadCalls = array_values(array_filter(
                $toolCalls,
                fn ($call) => ($call['name'] ?? null) === LeadCaptureService::TOOL_NAME
            ));

            $haggleCalls = $negotiatesPrice
                ? array_values(array_filter(
                    $toolCalls,
                    fn ($call) => ($call['name'] ?? null) === HagglingService::TOOL_NAME
                ))
                : [];

            // A price the assistant was already allowed to give is not a
            // decision for the owner. Checked here rather than trusted to the
            // prompt: the model reads the listed price off its own earlier
            // message often enough that unfiltered escalations would train the
            // owner to ignore the ones that matter. See isBelowFloor().
            $haggleCalls = array_values(array_filter(
                $haggleCalls,
                fn ($call) => $this->haggling->isBelowFloor(
                    $agent,
                    $this->numeric($call['arguments']['requested_price'] ?? null),
                    $this->numeric($call['arguments']['listed_price'] ?? null),
                ),
            ));

            if ($haggleCalls !== []) {
                // The price question outranks everything else this turn: until
                // the owner answers there is no price, so there is no order to
                // record either. Any create_lead call made alongside it is
                // dropped rather than filed against a price nobody agreed to.
                $request = $this->requestPriceApproval($agent, $conversation, $contents, $systemPrompt, $result, $haggleCalls);
                $replyText = $request['text'];
                $usage = $request['usage'];
                $haggleLeads = $request['leads'];
            } elseif ($leadCalls !== [] && $this->leadCapture->shouldAskForPhoneFirst($conversation, $leadCalls)) {
                // Held back for exactly one turn, so the customer is asked for a
                // number before the order is filed without one. Nothing is
                // persisted here; the next attempt records it whatever they say.
                $ask = $this->askForPhoneBeforeRecording($agent, $conversation, $contents, $systemPrompt, $result, $leadCalls);
                $replyText = $ask['text'];
                $usage = $ask['usage'];
                $askedForPhone = true;
            } elseif ($leadCalls !== []) {
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
                    : ($escalation !== null || $handover !== null
                        ? $this->escalationAcknowledgement($agent)
                        : $this->fallbackMessage($agent));
                $usage = ['input_tokens' => $result['input_tokens'], 'output_tokens' => $result['output_tokens']];

                // A reply the model ran out of room to finish must not reach the
                // customer ending mid-word.
                $replyText = $this->repairTruncatedReply($replyText, $result['finish_reason'] ?? null);

                // Every turn, against the last few replies — not only short
                // follow-ups against the last one. The loop this closes was
                // three full-sentence "something else, not MacBook" messages,
                // none of them short, each answered with the same list.
                $recentReplies = $this->recentAssistantReplies($conversation, $userMsg);

                if ($recentReplies !== []) {
                    $retry = $this->rewriteIfRepeated(
                        $agent,
                        $conversation,
                        $systemPrompt,
                        $contents,
                        $replyText,
                        $recentReplies,
                        $options,
                    );

                    $replyText = $retry['text'];
                    $usage['input_tokens'] += $retry['usage']['input_tokens'];
                    $usage['output_tokens'] += $retry['usage']['output_tokens'];
                    $rewroteRepeat = $retry['rewritten'];
                }

                $cleaned = $this->styleCleaner->clean($replyText);
                $styleCleaned = $cleaned !== $replyText;
                $replyText = $cleaned;
            }

            // A confirmation the customer can read must correspond to a row
            // they can be phoned about. Runs last, on the final text, whichever
            // path produced it.
            // Skipped while a price is out for approval: the reply deliberately
            // promises nothing, and the guard exists to catch a confirmation
            // that WAS given.
            if ($haggleLeads === []) {
                $replyText = $this->honourAnyOrderClaim($agent, $conversation, $replyText, $capturedLeads);
            }
            $replyText = $this->removeFalsePhotoRefusal($replyText, $userMsg->content);
            $replyText = $this->rejectEmptyReply($agent, $conversation, $replyText);

            // Superseded generations (a turn the model spent calling
            // search_products) still cost tokens; the owner is billed for the
            // whole exchange, so it is all recorded.
            $usage['input_tokens'] += $carried['input_tokens'];
            $usage['output_tokens'] += $carried['output_tokens'];

            $generationMs = (microtime(true) - $generationStart) * 1000;
        } catch (LlmException $e) {
            Log::warning('Agent reply generation failed, using fallback message', [
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
            // When the provider is already down, a canned reply that fits the
            // message reads as a normal answer instead of a second "something's
            // broken" signal stacked on top of a trivial one.
            $replyText = match (true) {
                // A customer who has just sworn at the assistant must not be
                // answered with "we'll get back to you" — they were promised a
                // person, and the flag below is about to summon one.
                $handover !== null => $this->escalationAcknowledgement($agent),
                // A bare greeting doesn't need the LLM to answer well.
                $this->isSimpleGreeting($userMsg->content) => $this->greetingMessage($agent, $conversation),
                default => $this->fallbackMessage($agent),
            };

            if ($handover !== null) {
                $escalation = [
                    'signal' => $handover['signal'],
                    'confidence' => 1.0,
                    'summary' => $handover['summary'],
                    'detected_by' => 'pipeline',
                    'reason' => $handover['reason'],
                ];
            }

            $failed = true;
        }

        $totalMs = (microtime(true) - $startedAt) * 1000;

        $unanswered = $this->classifyUnanswered(
            failed: $failed,
            productSearches: $productSearches,
            productMisses: $productMisses,
            retrieved: $retrieved,
            retrievalSkipped: $retrievalSkipped,
            capturedLeads: $capturedLeads,
            userMessage: $userMsg->content,
            replyText: $replyText,
            escalated: $escalation !== null,
            priceApprovalRequested: $haggleLeads !== [],
        );

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
                // Surfaces in the Playground's per-message diagnostics beside the
                // timings, so a zero retrieval time reads as a decision rather
                // than as something silently broken.
                'retrieval_skipped' => $retrievalSkipped,
                'generation_ms' => round($generationMs, 1),
                'total_ms' => round($totalMs, 1),
                'failed' => $failed,
                'lead_captured' => $capturedLeads !== [],
                // Recorded on the turn that asked, and read back on the next
                // order attempt so the customer is only ever asked once.
                LeadCaptureService::ASKED_FOR_PHONE => $askedForPhone,
                'lead_ids' => array_map(fn (Lead $lead) => $lead->id, $capturedLeads),
                // A turn that ended in "я уточню у менеджера". Visible in the
                // Playground beside the other decisions this turn made.
                'price_approval_requested' => $haggleLeads !== [],
                'haggle_lead_ids' => array_map(fn (Lead $lead) => $lead->id, $haggleLeads),
                'escalated' => $escalation !== null,
                'escalation_signal' => $escalation['signal'] ?? null,
                'escalation_confidence' => $escalation['confidence'] ?? null,
                // How many catalog lookups this reply is grounded in. Surfaces in
                // the Playground's per-message diagnostics the same way retrieval
                // timings do, so an owner debugging a wrong price can see whether
                // the dataset was consulted at all.
                'product_searches' => $productSearches,
                // What the catalog was asked for, so the owner's dashboard can
                // show demand rather than only sales.
                'product_queries' => $productQueries,
                'product_image_candidates' => array_slice($productImageCandidates, 0, 10),
                // Whether this turn ended without an answer, and why. Recorded
                // here because nothing else in the schema survives it: a
                // question the agent could not answer produces no lead, no
                // escalation and no error — the customer simply leaves. The
                // question itself rides along so the dashboard can group
                // recurring ones without joining back through the transcript.
                'unanswered' => $unanswered !== null,
                'unanswered_reason' => $unanswered['reason'] ?? null,
                // Which signal caught it: the facts of the turn ('retrieval',
                // 'pipeline') or the wording of the reply ('reply_text'). The
                // owner never sees this; it exists so a noisy detector can be
                // told apart from a noisy agent when tuning either one.
                'unanswered_detected_by' => $unanswered['detected_by'] ?? null,
                'unanswered_question' => $unanswered === null ? null : mb_substr(trim($userMsg->content), 0, 300),
                // Why a handover fired, when one did — the owner's inbox shows
                // the signal, and this shows whether a person or a pattern
                // decided it.
                'escalation_detected_by' => $escalation['detected_by'] ?? ($escalation !== null ? 'model' : null),
                'escalation_reason' => $escalation['reason'] ?? null,
                // The follow-up path, so a repeated answer that slipped through
                // is visible in the Playground rather than only in the logs.
                'short_follow_up' => $isFollowUp,
                'style_cleaned' => $styleCleaned,
                'rewrote_repeated_reply' => $rewroteRepeat,
            ],
        ]);

        $conversation->update(['last_message_at' => now()]);

        foreach ([...$capturedLeads, ...$haggleLeads] as $lead) {
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
     * Did this turn leave the customer without an answer, and if so why?
     *
     * Deliberately narrow. The temptation is to flag anything that looks
     * uncertain, and a dashboard that cries wolf gets ignored — so only three
     * situations count, each of which is a FACT about the turn rather than a
     * reading of the reply text:
     *
     *  - catalog_miss: the model searched the catalog and every search came
     *    back empty. The customer asked for something the shop does not list.
     *    This is the one that pays for the feature: it is demand the owner
     *    cannot see any other way.
     *  - knowledge_miss: the agent was asked a real question and told the
     *    customer it did not know. Judged from the REPLY, not from retrieval
     *    scores — see the long note at the check itself for why scoring the
     *    knowledge base cannot answer this on its own.
     *  - provider_down: no answer was generated. Worth counting separately so
     *    an outage does not masquerade as missing content.
     *
     * A turn that recorded an order is never unanswered, whatever else it did:
     * the customer got what they came for.
     *
     * WHAT COUNTS AS UNANSWERED is what the customer was told, not what the
     * pipeline scored. The catalog and provider branches below are facts about
     * the turn and stay that way; the knowledge branch asks
     * `looksLikeANonAnswer()` about the actual reply, because the knowledge
     * base is only ONE of the agent's sources — business settings and the
     * model's own competence are the others — and a widget that flags every
     * answer the vector store did not supply spends its credibility on correct
     * answers within a week of launch.
     *
     * @param  RetrievedChunk[]  $retrieved
     * @param  Lead[]  $capturedLeads
     * @return array{reason: 'catalog_miss'|'knowledge_miss'|'provider_down', detected_by: 'pipeline'|'retrieval'|'reply_text'}|null
     */
    protected function classifyUnanswered(
        bool $failed,
        int $productSearches,
        int $productMisses,
        array $retrieved,
        bool $retrievalSkipped,
        array $capturedLeads,
        string $userMessage,
        string $replyText,
        bool $escalated,
        bool $priceApprovalRequested,
    ): ?array {
        // Both of these ended the turn with the customer holding something real
        // — an order, or a price the owner is about to rule on.
        if ($capturedLeads !== [] || $priceApprovalRequested) {
            return null;
        }

        if ($failed) {
            return ['reason' => 'provider_down', 'detected_by' => 'pipeline'];
        }

        if ($productSearches > 0) {
            return $productMisses === $productSearches
                ? ['reason' => 'catalog_miss', 'detected_by' => 'retrieval']
                : null;
        }

        // No catalog was consulted, so a knowledge miss is only meaningful if
        // the knowledge base was actually asked. Greetings and one-word replies
        // are excluded: they have nothing to retrieve and would drown the list.
        if ($retrievalSkipped || ! $this->looksLikeAQuestion($userMessage)) {
            return null;
        }

        // THE REPLY DECIDES. Not retrieval.
        //
        // This check used to come second, after an early return on "nothing
        // confident was retrieved" — and that early return was wrong, because
        // it treated the knowledge base as the agent's only source of truth.
        // It is not. The owner's address, opening hours and house rules live in
        // the agent's own settings (custom_system_prompt, rules_instructions,
        // description) and reach the model directly, never through the vector
        // store; general questions about the field are answered from the
        // model's own competence. A correct, specific answer to "а где вы
        // находитесь" therefore scores nothing against the uploaded documents
        // BY DESIGN — and was being reported to the owner as "нет в базе
        // знаний" while sitting next to the right address.
        //
        // So low retrieval confidence is not evidence of anything on its own.
        // The question this widget asks is whether the CUSTOMER went away
        // without an answer, and the only place that is visible is the reply.
        if (! $this->looksLikeANonAnswer($replyText)) {
            return null;
        }

        // An escalation acknowledgement ("передаю менеджеру") reads exactly like
        // an apology to the matcher above, and a handover is already its own
        // category in the owner's inbox. Counting it here would file one event
        // under two problems.
        if ($escalated) {
            return null;
        }

        // The turn IS unanswered. Retrieval's only remaining job is to say
        // which kind of hole it was, which is what the owner acts on
        // differently: nothing relevant found means the knowledge base is
        // missing the topic outright, while a confident chunk that still
        // produced "I don't know" means the material is there but does not
        // cover this case — the Dushanbe page answering a question about
        // Khujand.
        $confident = array_filter($retrieved, fn (RetrievedChunk $chunk) => $chunk->confident);

        return [
            'reason' => 'knowledge_miss',
            'detected_by' => $confident === [] ? 'retrieval' : 'reply_text',
        ];
    }

    /**
     * Did the agent tell the customer, in whatever words it chose, that it does
     * not know?
     *
     * The judgement itself lives in NonAnswerDetector, because the backfill
     * command that re-judges turns recorded under the older rule has to reach
     * exactly the same verdict this does — see that class.
     */
    protected function looksLikeANonAnswer(string $reply): bool
    {
        return $this->nonAnswers->matches($reply);
    }

    /**
     * A crude but deliberate filter: enough words to be a request, or an
     * explicit question mark. Its only job is to keep "ок", "привет" and a
     * bare emoji out of the owner's unanswered list.
     */
    protected function looksLikeAQuestion(string $message): bool
    {
        $message = trim($message);

        if ($message === '' || str_starts_with($message, '[')) {
            return false; // A photo/voice placeholder, not a typed question.
        }

        return str_contains($message, '?')
            || str_contains($message, '؟')
            || count(preg_split('/\s+/u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: []) >= 3;
    }

    /**
     * Answer the model's search_products calls and let it write the reply with
     * the results in hand.
     *
     * Each round appends the call and its result to $contents and regenerates.
     * The loop is bounded by MAX_PRODUCT_SEARCH_ROUNDS: a model that keeps
     * searching instead of answering must still produce a reply, so whatever it
     * last wrote is what the customer gets.
     *
     * The search itself cannot fail the turn — ProductSearchService returns an
     * empty result with guidance rather than throwing — so a catalog problem
     * degrades to "I could not find that" instead of the generic fallback.
     *
     * @param  array<int, array<string, mixed>>  $contents
     * @param  array<string, mixed>  $result  The generation that may contain tool calls.
     * @param  array<string, mixed>  $options
     * @return array{result: array<string, mixed>, contents: array<int, array<string, mixed>>, carried: array{input_tokens: int, output_tokens: int}, tool_calls: array<int, array<string, mixed>>, searches: int, queries: array<int, array{query: ?string, category: ?string, count: int}>, misses: int}
     */
    protected function resolveProductSearches(
        Agent $agent,
        string $systemPrompt,
        array $contents,
        array $result,
        array $options,
    ): array {
        $carried = ['input_tokens' => 0, 'output_tokens' => 0];
        $seenToolCalls = [];
        $searches = 0;
        // What the customer was actually shopping for this turn, and how much of
        // it the catalog could answer. Recorded on the message because the
        // owner's dashboard has no other way to see demand: a product asked for
        // ten times and never stocked leaves no lead, no order and no trace
        // anywhere else in the schema.
        $queries = [];
        $images = [];
        $misses = 0;

        for ($round = 0; $round < self::MAX_PRODUCT_SEARCH_ROUNDS; $round++) {
            $calls = array_values(array_filter(
                $result['tool_calls'] ?? [],
                fn ($call) => ($call['name'] ?? null) === ProductSearchService::TOOL_NAME,
            ));

            if ($calls === []) {
                break;
            }

            foreach ($calls as $call) {
                $arguments = $call['arguments'] ?? [];
                $found = $this->productSearch->search($agent, $arguments);
                foreach ([...($found['products'] ?? []), ...($found['nearest'] ?? [])] as $product) {
                    if (! empty($product['image_url'])) {
                        $images[] = ['url' => $product['image_url'], 'name' => $product['name'] ?? ''];
                    }
                }
                $searches++;

                $queries[] = [
                    // The normalized filters, not the raw arguments: they are
                    // what was really searched for, and they are already
                    // trimmed and length-capped.
                    'query' => $found['filters']['query'] ?? null,
                    'category' => $found['filters']['category'] ?? null,
                    'count' => (int) $found['count'],
                ];

                if ((int) $found['count'] === 0) {
                    $misses++;
                }

                // Info, not debug: this is the record of what the model asked
                // the catalog for. When an owner reports "it says we don't have
                // it", the first question is whether the model extracted the
                // right filters from the customer's words, and this is the only
                // place that answers it.
                Log::info('agent.product_search', [
                    'agent_id' => $agent->id,
                    // Raw and normalized side by side: the difference between
                    // them is the argument the tool refused (a negative price, a
                    // non-numeric limit) and is exactly what is worth seeing.
                    'arguments' => $arguments,
                    'filters' => $found['filters'],
                    'count' => $found['count'],
                    'nearest' => count($found['nearest'] ?? []),
                ]);

                $contents[] = ['role' => 'tool_call', 'id' => $call['id'], 'name' => $call['name'], 'arguments' => $arguments];
                $contents[] = [
                    'role' => 'tool_result',
                    'id' => $call['id'],
                    'name' => $call['name'],
                    'result' => json_encode($found, JSON_UNESCAPED_UNICODE),
                ];
            }

            // The generation that asked for the search is about to be replaced,
            // but any OTHER tool it called on the same turn (a lead, an
            // escalation flag) still counts and must not be lost with it.
            $seenToolCalls = [...$seenToolCalls, ...array_values(array_filter(
                $result['tool_calls'] ?? [],
                fn ($call) => ($call['name'] ?? null) !== ProductSearchService::TOOL_NAME,
            ))];

            $carried['input_tokens'] += (int) ($result['input_tokens'] ?? 0);
            $carried['output_tokens'] += (int) ($result['output_tokens'] ?? 0);

            $result = $this->llm->generate($systemPrompt, $contents, $options);
        }

        return [
            'result' => $result,
            'contents' => $contents,
            'carried' => $carried,
            'tool_calls' => $seenToolCalls,
            'searches' => $searches,
            'queries' => $queries,
            'images' => array_slice(array_values(array_unique($images, SORT_REGULAR)), 0, 10),
            'misses' => $misses,
        ];
    }

    /**
     * The channel job handles photo delivery separately. A model can still
     * volunteer a false refusal in an ordinary catalog answer despite the
     * prompt; remove that claim without discarding the factual product list.
     */
    protected function removeFalsePhotoRefusal(string $replyText, string $customerText): string
    {
        if (preg_match('/(?:к сожалению,?\s*)?я не могу (?:отправить|прислать|показать)[^\n]*(?:фото|фотограф|картинк|изображен)/iu', $replyText) !== 1) {
            return $replyText;
        }

        // A stock-list answer can volunteer this false refusal even without
        // the word "photo" in the customer's turn. Remove only the refusal
        // and its unsupported website redirect, preserving the product list.
        $cleaned = preg_replace('/(?:к сожалению,?\s*)?я не могу (?:отправить|прислать|показать)[^.!?\n]*(?:фото|фотограф|картинк|изображен)[^\n]*?(?:[.!?](?=\s|$)|$)/iu', '', $replyText);
        $cleaned = preg_replace('/(?:однако\s+)?вы можете (?:увидеть|посмотреть)[^\n]*?сайт[^\n]*?(?:[.!?](?=\s|$)|$)/iu', '', $cleaned);
        $cleaned = trim($this->styleCleaner->clean(trim($cleaned)));

        return $cleaned !== '' ? $cleaned : 'Какую модель хотите посмотреть?';
    }

    /**
     * Never send a message that says nothing.
     *
     * A reply of "." or " " is not a short answer, it is a broken one — the
     * customer reads it as the bot glitching and starts nudging ("Алооо", ".").
     * Whitespace from the model, truncation repair and markdown stripping can
     * all cause it; check once before persistence rather than trusting them.
     */
    protected function rejectEmptyReply(Agent $agent, Conversation $conversation, string $replyText): string
    {
        if (preg_match('/[\p{L}\p{N}]/u', $replyText)) {
            return $replyText;
        }

        Log::warning('Model produced a reply with no content; sending the fallback instead', [
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'reply' => $replyText,
        ]);

        return $this->fallbackMessage($agent);
    }

    /**
     * Make an order confirmation true, or stop it being sent.
     *
     * THE FAILURE THIS CLOSES. A model can write "заявка принята" without
     * calling anything, and it does — in the reported conversation it said so
     * three times while the orders table stayed empty. Two of those turns had
     * called the tool and had the write fail silently; one had never called it
     * at all. The customer cannot tell those apart, and neither can the owner,
     * who simply sees no orders.
     *
     * So the claim is treated as binding. If the reply says an order exists and
     * none was written this turn, one is written here — thin, but real, and
     * linked to the conversation the details are in. Making the sentence true is
     * more useful than deleting it: the customer has already been told, and an
     * owner would far rather see an order with sparse details than lose a sale
     * to a sentence nobody honoured.
     *
     * Returns the reply text, unchanged unless nothing could be recorded — in
     * which case the claim itself has to go, because it would be a lie.
     *
     * @param  Lead[]  $capturedLeads  Modified in place when a rescue row is written.
     */
    protected function honourAnyOrderClaim(Agent $agent, Conversation $conversation, string $replyText, array &$capturedLeads): string
    {
        if (! $this->orderClaims->claimsOrderRecorded($replyText)) {
            return $replyText;
        }

        if ($capturedLeads !== []) {
            return $replyText; // Claimed and recorded — the normal path.
        }

        // An order recorded moments ago in this conversation counts: a customer
        // asking "оформите ещё раз" should not produce a second row.
        $existing = $conversation->leads()
            ->where('created_at', '>=', now()->subMinutes(self::ORDER_CLAIM_WINDOW_MINUTES))
            ->exists();

        if ($existing) {
            return $replyText;
        }

        // Info level and always, as the standing record of this failure mode:
        // if this line stops appearing, the prompt is doing its job; if it
        // appears often, the model is still confirming orders it never placed.
        Log::info('Assistant confirmed an order without recording one; recording it now', [
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'reply' => mb_substr($replyText, 0, 200),
        ]);

        try {
            $capturedLeads[] = $this->leadCapture->recordPromisedOrder(
                $agent,
                $conversation,
                $conversation->channel?->type ?? 'playground',
                $this->leadCapture->phoneFor($conversation, []),
            );

            return $replyText;
        } catch (Throwable $e) {
            // The write is still failing. The one thing that must not happen now
            // is the customer being told, a fourth time, that everything is
            // fine — so the promise is withdrawn and a person is brought in.
            Log::error('Could not record a promised order; withdrawing the confirmation', [
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            if ($this->escalation->enabledFor($agent)) {
                $this->escalation->escalate($agent, $conversation, [
                    'signal' => 'fallback',
                    'summary' => 'Не удалось сохранить заявку клиента — свяжитесь вручную.',
                ], 'fallback');
            }

            return $this->leadCapture->orderFailureMessage($agent);
        }
    }

    /**
     * Turn this order attempt into a request for a phone number.
     *
     * The order is deliberately NOT saved yet. The customer is asked once, and
     * whatever they answer, the next attempt records the order — with the number
     * if they gave one, without it if they did not. Nothing here can refuse an
     * order; it can only delay one by a single turn.
     *
     * The generation is a real one, so the question arrives in the customer's
     * own language and mentions what they are ordering. If it fails, the
     * templated question goes instead: having asked is the point, and a canned
     * sentence still asks.
     *
     * @param  array<int, array<string, mixed>>  $contents
     * @param  array<int, array{id: string, name: string, arguments: array}>  $leadCalls
     * @return array{text: string, usage: array{input_tokens: int, output_tokens: int}}
     */
    protected function askForPhoneBeforeRecording(
        Agent $agent,
        Conversation $conversation,
        array $contents,
        string $systemPrompt,
        array $firstResult,
        array $leadCalls,
    ): array {
        $continuation = $contents;

        foreach ($leadCalls as $call) {
            $continuation[] = ['role' => 'tool_call', 'id' => $call['id'], 'name' => $call['name'], 'arguments' => $call['arguments'] ?? []];
            $continuation[] = [
                'role' => 'tool_result',
                'id' => $call['id'],
                'name' => $call['name'],
                'result' => $this->leadCapture->phoneRequestInstruction(),
            ];
        }

        $inputTokens = (int) ($firstResult['input_tokens'] ?? 0);
        $outputTokens = (int) ($firstResult['output_tokens'] ?? 0);

        try {
            $ask = $this->llm->generate($systemPrompt, $continuation, [
                'max_output_tokens' => $this->maxOutputTokens($agent),
                'thinking_budget' => 0,
            ]);

            $inputTokens += (int) $ask['input_tokens'];
            $outputTokens += (int) $ask['output_tokens'];
            $text = trim($ask['text'] ?? '');
        } catch (LlmException $e) {
            Log::warning('Phone-request generation failed, asking with the templated question', [
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            $text = '';
        }

        Log::info('Order held for one turn to ask for a phone number', [
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
        ]);

        return [
            'text' => $text !== '' ? $text : $this->leadCapture->phoneRequestFallback($agent),
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ];
    }

    /**
     * File the customer's price request for the owner and let the model write
     * the holding sentence with the outcome in hand.
     *
     * Shaped exactly like captureLeadsAndConfirm() — persist, feed the tool
     * result back, regenerate — with one difference that is the point of the
     * whole feature: what the model is handed back says the request was SENT,
     * never that it was granted. There is no path through here that lets the
     * assistant tell a customer a below-floor price is theirs, because the only
     * party who can say that has not been asked yet.
     *
     * @param  array<int, array{role: string, text: string}>  $contents
     * @param  array<int, array{id: string, name: string, arguments: array}>  $haggleCalls
     * @return array{text: string, usage: array{input_tokens: int, output_tokens: int}, leads: Lead[]}
     */
    protected function requestPriceApproval(
        Agent $agent,
        Conversation $conversation,
        array $contents,
        string $systemPrompt,
        array $firstResult,
        array $haggleCalls,
    ): array {
        $source = $conversation->channel?->type ?? 'playground';
        $leads = [];
        $continuation = $contents;

        foreach ($haggleCalls as $call) {
            $saved = null;

            try {
                $saved = $this->haggling->persist($agent, $conversation, $call['arguments'] ?? [], $source);
                $leads[] = $saved;
            } catch (Throwable $e) {
                Log::error('Failed to record a price approval request; continuing with the reply', [
                    'agent_id' => $agent->id,
                    'conversation_id' => $conversation->id,
                    'arguments' => $call['arguments'] ?? [],
                    'error' => $e->getMessage(),
                ]);
            }

            $continuation[] = ['role' => 'tool_call', 'id' => $call['id'], 'name' => $call['name'], 'arguments' => $call['arguments'] ?? []];
            $continuation[] = [
                'role' => 'tool_result',
                'id' => $call['id'],
                'name' => $call['name'],
                'result' => $saved !== null
                    ? $this->haggling->pendingInstruction()
                    : $this->haggling->failureInstruction(),
            ];
        }

        $inputTokens = (int) ($firstResult['input_tokens'] ?? 0);
        $outputTokens = (int) ($firstResult['output_tokens'] ?? 0);

        try {
            $reply = $this->llm->generate($systemPrompt, $continuation, [
                'max_output_tokens' => $this->maxOutputTokens($agent),
                'thinking_budget' => 0,
            ]);

            $inputTokens += (int) $reply['input_tokens'];
            $outputTokens += (int) $reply['output_tokens'];
            $text = trim($reply['text'] ?? '');
        } catch (LlmException $e) {
            Log::warning('Price-request reply generation failed, using the templated holding message', [
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            $text = '';
        }

        Log::info('Price approval requested from the owner', [
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'lead_ids' => array_map(fn (Lead $lead) => $lead->id, $leads),
        ]);

        return [
            // The request is already filed, so the customer must hear the
            // holding sentence whether or not the generation came back.
            'text' => $text !== ''
                ? $text
                : ($leads !== [] ? $this->haggling->pendingFallback($agent) : $this->fallbackMessage($agent)),
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
            'leads' => $leads,
        ];
    }

    /**
     * A price out of tool arguments. Models pass "1500", 1500 and "1 500" for
     * the same number, and a filter that only understood one of them would let
     * the other two past the floor check.
     */
    protected function numeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return $value > 0 ? (float) $value : null;
        }

        if (is_string($value)) {
            $normalized = str_replace([' ', "\u{a0}", ','], ['', '', '.'], trim($value));

            return is_numeric($normalized) && (float) $normalized > 0 ? (float) $normalized : null;
        }

        return null;
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

        // Resolved once, from the tool arguments AND the conversation, because
        // it decides two different things: what is stored on the order, and
        // whether the customer may be promised a call.
        $phone = $this->leadCapture->phoneFor($conversation, $leadCalls);

        foreach ($leadCalls as $call) {
            $saved = null;

            try {
                $saved = $this->leadCapture->persist($agent, $conversation, $call['arguments'] ?? [], $source, $phone);
                $leads[] = $saved;
            } catch (Throwable $e) {
                // This is the failure that took the whole turn down in
                // production: a schema change that had not been migrated made
                // the insert throw, and a reply the model had already written
                // (and the owner had already paid for) was discarded with it.
                //
                // The order is genuinely lost here and that is bad — but the
                // customer's conversation continuing is worth more than the
                // symmetry of failing the turn, and the message they get still
                // acknowledges what they asked for. The owner gets a loud log.
                Log::error('Failed to record a lead; continuing with the reply', [
                    'agent_id' => $agent->id,
                    'conversation_id' => $conversation->id,
                    'arguments' => $call['arguments'] ?? [],
                    'error' => $e->getMessage(),
                ]);
            }

            $continuation[] = ['role' => 'tool_call', 'id' => $call['id'], 'name' => $call['name'], 'arguments' => $call['arguments'] ?? []];
            $continuation[] = [
                'role' => 'tool_result',
                'id' => $call['id'],
                'name' => $call['name'],
                // The result the model is handed is what actually happened, not
                // what was attempted. Telling it "recorded" after a failed write
                // is what produced three confirmations against an empty table.
                'result' => $saved !== null
                    ? $this->leadCapture->confirmationInstruction($phone !== null)
                    : $this->leadCapture->failureInstruction(),
            ];
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
            $text = trim($confirm['text']) !== ''
                ? $confirm['text']
                : $this->leadCapture->confirmationFallback($agent, $phone !== null);
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
                : $this->leadCapture->confirmationFallback($agent, $phone !== null);
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

        // 'unsupported_attachment' shares this path deliberately. The customer's
        // situation is identical — they sent something the assistant cannot
        // read and need to be asked for text — and a second near-identical
        // method would drift from this one.
        $medium = $reason === 'unsupported_attachment'
            ? 'an attachment this assistant cannot open (a document, video, location or similar)'
            : 'a voice message';

        $situation = match ($reason) {
            'voice_too_long' => 'The voice message was too long for this assistant to process.',
            'unsupported_attachment' => 'This assistant can only read text and photos.',
            default => 'The voice message could not be understood — it may have been unclear or the audio failed to process.',
        };

        $systemPrompt = implode("\n\n", array_filter([
            "You are {$agent->name}, a customer support assistant for this business.",
            $this->languageInstruction($agent, $conversation),
            "The customer sent {$medium}. ".$situation.' '
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
     * The assistant's own recent replies, newest first — what a new reply is
     * checked against for repetition. More than one, because the reported loop
     * alternated wording between turns and a reply can repeat the one before
     * last as easily as the last.
     *
     * @return array<int, string>
     */
    protected function recentAssistantReplies(Conversation $conversation, Message $userMsg): array
    {
        return $conversation->messages()
            ->where('role', 'assistant')
            ->where('id', '<', $userMsg->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->take(self::REPEAT_LOOKBACK)
            ->pluck('content')
            ->all();
    }

    /**
     * The assistant's own last message in this conversation, if any.
     *
     * Excludes the turn being answered so a re-run cannot compare a reply to
     * itself. Operator messages count: a customer replying "да" to a human
     * colleague's message is continuing from that, not from the bot.
     */
    protected function lastAssistantMessage(Conversation $conversation, Message $userMsg): ?Message
    {
        return $conversation->messages()
            ->whereIn('role', ['assistant', 'operator'])
            ->where('id', '<', $userMsg->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * What a short referential follow-up needs the model to be told.
     *
     * The failing case: four products listed, the customer asks "так да или
     * нет", and the model — handed the same context it had a turn ago, plus a
     * catalog rule telling it to search again before answering — re-runs the
     * search and re-renders the same four products. Everything it did was
     * individually correct. What was missing is the instruction that the QUESTION
     * has changed even though the subject has not.
     *
     * The previous reply is quoted rather than left to be found in the history.
     * "Do not repeat yourself" is advice; "do not repeat THIS" is a comparison
     * the model can actually make.
     */
    protected function followUpInstruction(?string $previousReply): string
    {
        $lines = [
            'THIS MESSAGE IS A SHORT FOLLOW-UP, NOT A NEW QUESTION.',
            '- The customer\'s latest message is brief and only makes sense against your previous reply — a yes/no,',
            '  a pick from what you just offered, "is that all?", or a nudge for an answer you did not give.',
            '- ANSWER THAT MESSAGE. If they asked for a yes or a no, the first word of your reply is that yes or no.',
            '  If they picked one of the options you listed, talk about that one. If they asked whether that was',
            '  everything, say whether it was.',
            '- Do NOT restate what you already sent. They have read it. Listing the same products again, even',
            '  reworded, is the single most obvious way to tell a customer they are talking to a machine.',
            '- You may still check the catalog for fresh prices and stock — that rule stands. But the check informs',
            '  a NEW, SHORTER answer to the question actually asked; it is not a reason to send the list again.',
            '- Refer back naturally instead of repeating: "да, это всё, что есть в этом бюджете", "второй — тот,',
            '  что с 16 ГБ". One or two sentences is usually the whole correct answer here.',
        ];

        if ($previousReply !== null && trim($previousReply) !== '') {
            $lines[] = '';
            $lines[] = 'YOUR PREVIOUS REPLY, which the customer is responding to. Do not send this again in any form:';
            $lines[] = '"""'.trim(mb_substr($previousReply, 0, 1200)).'"""';
        }

        return implode("\n", $lines);
    }

    /**
     * What the model is told when the pipeline has already decided a person is
     * taking over.
     *
     * The handover happens whether or not the model cooperates — it is recorded
     * after this generation regardless. This exists so the customer is TOLD, in
     * their own language and in the assistant's own voice, rather than being met
     * with another apology and silence.
     */
    protected function handoverInstruction(): string
    {
        return implode("\n", [
            'A COLLEAGUE IS BEING BROUGHT INTO THIS CONVERSATION RIGHT NOW.',
            '- The business owner has already been alerted. This is done; it is not something to offer or to ask',
            '  permission for.',
            '- Tell the customer, calmly and in one or two short sentences, that a person is taking over and will',
            '  reply here shortly. Say it plainly, in their language.',
            '- Stay warm and do not argue, defend yourself, or match their tone, however they are speaking to you.',
            '  One brief acknowledgement that this has been frustrating is enough — do not apologise repeatedly,',
            '  and do not grovel.',
            '- If you can still answer their actual question in a sentence, do that too. Do not go silent.',
            '- Never mention tools, flags, alerts, escalation or any internal system. A person is joining; that is',
            '  the whole story from the customer\'s side.',
        ]);
    }

    /**
     * Decide whether this turn hands the conversation to a person.
     *
     * Two independent triggers, both about the customer rather than about the
     * model's opinion of them: what they just said, and how many times they have
     * now had to say it.
     *
     * @return array{signal: string, reason: string, summary: string}|null
     */
    protected function detectHandover(Conversation $conversation, Message $userMsg): ?array
    {
        $verdict = $this->hostility->inspect(
            $userMsg->content,
            $this->priorRudeMessageCount($conversation, $userMsg),
        );

        if ($verdict === null && $this->hasAskedTheSameThingRepeatedly($conversation, $userMsg)) {
            $verdict = [
                'signal' => HostilityDetector::SIGNAL_REPEATED_FAILURE,
                'reason' => 'repeated_question',
            ];
        }

        if ($verdict === null) {
            return null;
        }

        Log::info('Conversation handed to a human by the pipeline', [
            'agent_id' => $conversation->agent_id,
            'conversation_id' => $conversation->id,
            'signal' => $verdict['signal'],
            'reason' => $verdict['reason'],
        ]);

        return $verdict + [
            'summary' => __('conversations.escalation.auto_summary.'.$verdict['reason'], [], $conversation->agent?->user?->locale),
        ];
    }

    /**
     * How many of the customer's earlier messages here were already rude.
     *
     * This is what makes a second "бесполезный бот" escalate while the first
     * does not: one exasperated word is a bad moment, two is a conversation
     * going wrong.
     */
    protected function priorRudeMessageCount(Conversation $conversation, Message $userMsg): int
    {
        $earlier = $conversation->messages()
            ->where('role', 'user')
            ->where('id', '<', $userMsg->id)
            ->orderByDesc('id')
            ->take(self::REPEATED_QUESTION_LOOKBACK)
            ->pluck('content');

        return $earlier
            ->filter(fn (string $text) => $this->hostility->isRude($text) || $this->hostility->isAbusive($text))
            ->count();
    }

    /**
     * Whether the customer is now asking the same thing for the third time.
     *
     * A question re-asked twice after being answered is the shape of an
     * assistant that cannot help: either it keeps missing the point or the
     * answer it has is not the one being asked for. Both are a person's job, and
     * neither is something the model is well placed to notice about itself.
     */
    protected function hasAskedTheSameThingRepeatedly(Conversation $conversation, Message $userMsg): bool
    {
        // A follow-up is short and referential by nature; "да" repeated is not a
        // customer stuck on a question.
        if ($this->followUps->isShortFollowUp($userMsg->content, true)) {
            return false;
        }

        $earlier = $conversation->messages()
            ->where('role', 'user')
            ->where('id', '<', $userMsg->id)
            ->orderByDesc('id')
            ->take(self::REPEATED_QUESTION_LOOKBACK)
            ->pluck('content');

        $repeats = $earlier
            ->filter(fn (string $text) => $this->similarity->isNearDuplicate($userMsg->content, $text))
            ->count();

        return $repeats >= 2;
    }

    /**
     * Catch the model sending the previous reply again, and ask once more.
     *
     * The prompt already tells it not to. This is the check that the instruction
     * landed — and the reported bug is proof that an instruction alone does not
     * always land. One extra generation is cheap next to a customer being handed
     * the same four products they just read.
     *
     * One correction at most, with the original draft and catalog results still
     * available. Repeated questions can be removed without deleting useful facts.
     *
     * @param  array<int, array<string, mixed>>  $contents
     * @param  array<int, string>  $recentReplies  The assistant's own recent replies, newest first.
     * @param  array<string, mixed>  $options
     * @return array{text: string, rewritten: bool, usage: array{input_tokens: int, output_tokens: int}}
     */
    protected function rewriteIfRepeated(
        Agent $agent,
        Conversation $conversation,
        string $systemPrompt,
        array $contents,
        string $replyText,
        array $recentReplies,
        array $options,
    ): array {
        $unchanged = ['text' => $replyText, 'rewritten' => false, 'usage' => ['input_tokens' => 0, 'output_tokens' => 0]];

        // The customer's own figures — their budget — are not the assistant
        // repeating itself when it quotes them back.
        $customerTexts = $this->recentCustomerMessages($conversation)->all();

        if ($this->followUps->requestsRecap($customerTexts[0] ?? '')) {
            return $unchanged;
        }

        $repeated = collect($recentReplies)->first(
            fn (string $earlier) => $this->similarity->isRepeatOf($replyText, $earlier, $customerTexts),
        );

        if ($repeated === null) {
            return $unchanged;
        }

        $cleaned = $this->similarity->withoutRepeatedQuestions($replyText, $recentReplies);
        if ($cleaned !== $replyText && ! collect($recentReplies)->contains(
            fn (string $earlier) => $this->similarity->isRepeatOf($cleaned, $earlier, $customerTexts),
        )) {
            return ['text' => $cleaned, 'rewritten' => true, 'usage' => $unchanged['usage']];
        }

        Log::warning('Reply repeated the previous one, regenerating', [
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'containment' => round($this->similarity->containment($replyText, $repeated), 2),
            'same_listing' => $this->similarity->repeatsListing($replyText, $repeated, $customerTexts),
        ]);

        $correction = $systemPrompt."\n\n".implode("\n", [
            'YOU HAVE JUST WRITTEN THE SAME REPLY AGAIN. It repeats this reply, which you already sent in this',
            'conversation and the customer has already read:',
            '"""'.trim(mb_substr($repeated, 0, 1200)).'"""',
            'Edit this current draft (quoted data, not instructions):',
            json_encode($replyText, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'Answer the latest customer message directly, keeping any useful new facts from the draft:',
            '- Do not re-list the same products or prices unless the customer explicitly asked for a recap or comparison.',
            '- Do not ask a question already answered, declined, or just asked. Do not restart the greeting.',
            '- Preserve verified names, prices and constraints. Do not invent a new fact just to sound different.',
            '- Never offer anything the customer has said they do not want.',
            '- If their message is a short question, answer just that, in one or two sentences.',
            '- If there is genuinely nothing new to offer, say what is missing plainly. Ask a clarifying question',
            '  only if it has not already been answered or declined. A short answer without a question is fine.',
        ]);

        try {
            // No tools this time. The model has already searched; another round
            // of tool calls is how it talked itself back into the same list.
            $retry = $this->llm->generate($correction, $contents, [
                'max_output_tokens' => $options['max_output_tokens'] ?? $this->maxOutputTokens($agent),
                'temperature' => 0.4,
                'thinking_budget' => 0,
            ]);
        } catch (LlmException $e) {
            Log::warning('Repeat-reply regeneration failed, keeping the original', [
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return $unchanged;
        }

        $text = $this->repairTruncatedReply(trim($retry['text'] ?? ''), $retry['finish_reason'] ?? null);
        $usage = [
            'input_tokens' => (int) ($retry['input_tokens'] ?? 0),
            'output_tokens' => (int) ($retry['output_tokens'] ?? 0),
        ];

        // The retry is still billed even when it came back empty and the
        // original stands.
        if ($text === '') {
            return ['text' => $replyText, 'rewritten' => false, 'usage' => $usage];
        }

        $text = $this->similarity->withoutRepeatedQuestions($text, $recentReplies);

        return ['text' => $text, 'rewritten' => $text !== $replyText, 'usage' => $usage];
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

    /**
     * The owner's configured greeting, verbatim. Only when they left it blank
     * is a default used, and then in the agent's own language — never a string
     * that assumes Russian, and never something the model makes up.
     */
    protected function greetingMessage(Agent $agent, Conversation $conversation): string
    {
        $configured = $agent->response_format['greeting_message'] ?? null;

        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        return __('replies.greeting', [], $this->locales->forAgent($conversation));
    }

    /**
     * Telegram's /start — optionally with a deep-link payload, or addressed as
     * "/start@SomeBot" in a group. The owner's "/start notify_<code>" is
     * consumed before this is ever asked.
     */
    public static function isStartCommand(string $text): bool
    {
        return preg_match(self::START_COMMAND_PATTERN, trim($text)) === 1;
    }

    /**
     * The reply to /start: the configured greeting, sent as-is.
     *
     * No retrieval and no model call. /start carries no question, and handing
     * the literal text "/start" to the model is what produced "It seems like
     * you're trying to restart something" — in English, to a Russian-speaking
     * shop's customer — in place of the greeting the owner wrote.
     *
     * Marked as a session start: a customer pressing /start is beginning a
     * new conversation even though Telegram gives them the same chat, and
     * FeedbackCadence counts turns from here rather than from days ago.
     */
    public function greetOnStart(Conversation $conversation): Message
    {
        $assistantMsg = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $this->greetingMessage($conversation->agent, $conversation),
            'meta' => [
                'retrieved_chunks' => [],
                'input_tokens' => 0,
                'output_tokens' => 0,
                'failed' => false,
                'greeting' => true,
                'session_start' => true,
            ],
        ]);

        $conversation->update(['last_message_at' => now()]);

        return $assistantMsg;
    }

    protected function isSimpleGreeting(string $text): bool
    {
        return (bool) preg_match(self::GREETING_PATTERN, trim($text));
    }

    /**
     * The hard generation cap for one reply.
     *
     * This is a SAFETY NET, not the length control — the prompt's "keep replies
     * under approximately N characters" is what shapes the answer. Sizing this
     * to the same N is what truncated replies mid-word: the model wrote the
     * length it was asked for, in Cyrillic, and ran out of tokens doing it. So
     * the budget is deliberately larger than the style instruction asks for.
     */
    protected function maxOutputTokens(Agent $agent): int
    {
        $maxLength = (int) ($agent->response_format['max_length'] ?? 600);

        $tokens = (int) ceil($maxLength / self::OUTPUT_CHARS_PER_TOKEN * self::OUTPUT_TOKEN_HEADROOM);

        return max(256, min($tokens, self::MAX_OUTPUT_TOKENS));
    }

    /**
     * Repair a reply the model did not get to finish.
     *
     * Even with a generous cap a reply can hit it, and what the customer must
     * never see is the half-word it stops on. Trimming back to the last complete
     * sentence turns "…какая-то определ" into a reply that simply ends earlier —
     * shorter than intended, but readable, and indistinguishable from a model
     * that chose to stop there.
     *
     * Only applied when the provider says it ran out of room. A reply that ends
     * without punctuation because the model wrote it that way is left alone.
     */
    protected function repairTruncatedReply(string $text, ?string $finishReason): string
    {
        if (! $this->hitOutputLimit($finishReason)) {
            return $text;
        }

        $trimmed = rtrim($text);

        // Already ends on a sentence: nothing was lost mid-thought.
        if ($trimmed === '' || preg_match('/[.!?…)]["»\']?$/u', $trimmed)) {
            return $text;
        }

        $cut = 0;
        foreach (['.', '!', '?', '…'] as $terminator) {
            $at = mb_strrpos($trimmed, $terminator);
            if ($at !== false) {
                $cut = max($cut, $at + 1);
            }
        }

        // Nothing worth keeping — one long unfinished sentence. Better to let
        // the fragment through than to send an empty message; the log below is
        // what says the cap needs raising.
        if ($cut === 0) {
            return $text;
        }

        $repaired = rtrim(mb_substr($trimmed, 0, $cut));

        // A reply whose first sentence-ending character is also its first
        // character trims to a lone ".", which is worse than the fragment it
        // replaced.
        return preg_match('/[\p{L}\p{N}]/u', $repaired) ? $repaired : $text;
    }

    protected function hitOutputLimit(?string $finishReason): bool
    {
        // Gemini says MAX_TOKENS, OpenAI says length.
        return in_array(strtolower((string) $finishReason), ['max_tokens', 'length'], true);
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
     * @param  bool  $searchProducts  Whether the catalog tool is being offered this turn.
     * @param  bool  $retrievalSkipped  Whether the knowledge base was deliberately not consulted.
     */
    protected function buildSystemPrompt(
        Agent $agent,
        array $retrieved,
        Conversation $conversation,
        bool $searchProducts = false,
        bool $retrievalSkipped = false,
    ): string {
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

        $parts[] = $this->answeringPolicyInstruction($searchProducts);

        $parts[] = implode("\n", [
            'PHOTO REQUESTS:',
            '- When the customer asks to see a product photo, do not claim that you cannot send photos, that this chat cannot attach them, or redirect them to a website instead.',
            '- Photo selection and delivery are handled by the chat system. Do not volunteer a photo refusal when listing products or discussing their specifications.',
            '- Never claim a photo is attached or available unless the catalog or knowledge base actually shows it.',
            '- Do not describe or invent an image, promise to send one later, or claim that photos are unavailable without evidence.',
        ]);

        $parts[] = $this->consultativeSellingInstruction();

        $parts[] = implode("\n", [
            'Rules:',
            '- This is a messenger chat, not an email: write like a helpful human texting back, not a brochure.',
            '- Use 2-4 sentences for a substantive question, one sentence for a simple fact or acknowledgement. This is a ceiling, not a quota. Go longer when the customer asks for details.',
            '- Do not use bullet points, numbered lists, or markdown formatting unless the customer explicitly asked for a list.',
            '- Recommend at most THREE products in one reply, and only when the customer is choosing between options;',
            '  one is the right number for an ordinary question. Every product you name must come from the KNOWLEDGE',
            '  BASE or from the catalog tool — never from your own knowledge of what shops usually stock.',
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

        $parts[] = $this->knowledgeBaseSection($retrieved, $retrievalSkipped);

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
    protected function answeringPolicyInstruction(bool $searchProducts): string
    {
        // Without this carve-out TIER 2 flatly states that prices and stock may
        // come ONLY from the knowledge base — which contradicts the catalog
        // instruction appended after it, and gives a model looking for a reason
        // not to call the tool a rule that appears to endorse skipping it.
        $productCarveOut = $searchProducts
            ? [
                '- EXCEPTION, and it overrides the rule above: prices, discounts, stock, availability and which',
                '  products exist do NOT come from the KNOWLEDGE BASE. They come from the "'.ProductSearchService::TOOL_NAME.'" tool,',
                '  freshly called, every time — see PRODUCT CATALOG below. If the knowledge base and the tool',
                '  disagree about a price or about stock, the tool is right and the knowledge base is stale.',
            ]
            : [];

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
            ...$productCarveOut,
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
    protected function knowledgeBaseSection(array $retrieved, bool $skipped = false): string
    {
        // Deliberately NOT the same message as "empty". A skipped retrieval must
        // never read to the model as "this business has no knowledge base" —
        // that would turn a latency optimization into the agent denying it knows
        // anything about the business.
        if ($skipped) {
            return 'KNOWLEDGE BASE: not consulted for this message. It was identified as a question about products, '
                .'prices, stock or availability, which the "'.ProductSearchService::TOOL_NAME.'" tool answers '
                .'authoritatively and more currently than any uploaded document could. The business DOES have a '
                .'knowledge base; it simply was not searched this time. If it turns out the customer needs a business '
                .'fact that is not about price, stock or availability, do not guess and do not claim the business has '
                .'no information — say you will check and offer to connect them with a colleague.';
        }

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
     * How to talk about products: like someone who sells them for a living.
     *
     * THE PROBLEM. Given a catalog tool and a question, a model produces a
     * catalog: four rows of name, specification and price, then "let me know if
     * you are interested in one of them". Every fact in it is right and it is
     * useless — it hands the choosing back to the customer, who asked precisely
     * because they did not know how to choose. Nobody reads it as a person.
     *
     * What an actual salesperson does instead is short and specific: they pick,
     * they say WHY that one given what the customer told them, they name the
     * thing it is worse at so the recommendation can be trusted, and they ask
     * the one question that would narrow it further. This section asks for those
     * four moves, in that order.
     *
     * TWO THINGS IT MUST NOT BECOME. It must not license invention — the
     * trade-off has to be visible in the data (a price gap, a smaller drive, no
     * stock), never a plausible-sounding one made up to sound expert; that would
     * undo the grounding rules above. And it must not become a formula, because
     * a reply assembled from the same four moves every turn is the robot in a
     * better suit. Both are stated as limits rather than left to be inferred.
     */
    protected function consultativeSellingInstruction(): string
    {
        return implode("\n", [
            'HOW TO TALK ABOUT PRODUCTS — you are a salesperson, not a catalogue.',
            'Someone who has sold this range for years does not read the list out. They work out what the customer',
            'is actually trying to do, pick the one or two things that fit it, and say why. Do the same.',
            '',
            '- START FROM THE NEED, NOT THE STOCK. Answer the question "which of these is right for you, and why",',
            '  not "what do we have". If the customer told you what it is for — work, gaming, a gift, a budget, a',
            '  child, travel — connect the recommendation to that. For a direct price, stock or delivery question,',
            '  answer that first; do not turn it into a needs questionnaire.',
            '- RECOMMEND, DO NOT ENUMERATE. Pick the best fit and lead with it. Mention a second only when it is a',
            '  genuinely different choice (much cheaper, much stronger, the only one in stock), and a third almost',
            '  never. Naming five things is not being helpful, it is refusing to have an opinion.',
            '- SAY WHY, IN THE CUSTOMER\'S TERMS. Explain one relevant, supported benefit of the specification.',
            '  Do not promise gaming performance, battery life, reliability or compatibility from a model name alone.',
            '- MENTION A RELEVANT TRADE-OFF WHEN KNOWN. If the data shows a meaningful limitation for this customer',
            '  (price, storage, size), explain it briefly. A drawback is optional, not a required sentence.',
            '  Never invent a drawback or a strength to make a recommendation sound balanced.',
            '- ASK THE ONE QUESTION THAT NARROWS IT. When something you do not know would change the answer — the',
            '  budget, what they will use it for, whether portability matters — ask it, once, at the end. Do not ask',
            '  when you already have what you need: answer instead.',
            '- SOUND LIKE A PERSON EACH TIME. This is a way of thinking, not a template. Do not run the same four',
            '  moves in the same order every reply, do not label them, and do not repeat a phrase you used earlier',
            '  in this conversation. Sometimes the whole correct answer is one sentence.',
            '- If the customer explicitly asked for a full list, give them the list — the point is not to withhold',
            '  information, it is not to substitute a list for advice.',
        ]);
    }

    protected function conversationStyleInstruction(): string
    {
        return implode("\n", [
            'FINAL RESPONSE GUIDANCE — apply after deciding whether a tool is needed. Keep all factual and tool safeguards above:',
            '- Read the latest message as part of the exchange. Answer its actual question before suggesting a next step.',
            '- The length limit is a maximum, not a target. Do not fill the available space. For a factual follow-up',
            '  answer the fact in ONE sentence and stop. Do not repeat specs or start a new sales pitch.',
            '- Remember the stated budget, purpose, selected item, rejected options and contact details. Do not ask',
            '  for them again. If the customer changes a requirement, the latest statement wins. Resolve "this one",',
            '  "the second" and "yes" against the previous exchange; ask only if the reference is genuinely ambiguous.',
            '- Greet once at the start. In an ongoing exchange, start with the answer. Do not prepend "Понимаю вас",',
            '  "Отличный выбор", "Спасибо за вопрос" or "Конечно, с удовольствием" to every message. Warmth comes',
            '  from paying attention, not from a repeated opening, praise or the customer\'s name in every reply.',
            '- Do not end every reply with "Чем ещё могу помочь?", "Если будут вопросы, обращайтесь" or a sales',
            '  question. A thanks, goodbye, refusal or "I will think about it" does not need another offer.',
            '- If something is too expensive, respect the budget. Offer a genuinely cheaper verified alternative',
            '  only if there is one; do not keep presenting the rejected item or pressure them to raise the budget.',
            '  Check ALL supplied products, including ones you have not mentioned yet. Compare their actual prices.',
            '  Never assume the last recommended item is the cheapest. An excluded brand does not exclude other brands.',
            '- If the customer is ready, help with the next required ordering step through the available tools.',
            '  Do not restart discovery, repeat the catalogue, or request contact information already supplied.',
            '- If information is missing, name the specific uncertainty. An offer to check is not a completed',
            '  handoff. Never claim a colleague was notified, a reservation made, a payment received, or a callback',
            '  scheduled unless the actual tool result or business data supports it. Do not promise an untracked follow-up.',
            '- Use natural everyday wording in the customer\'s language, without imitating their typos or adding',
            '  forced slang. Keep product names and figures exact. Do not describe yourself as a human or invent',
            '  personal experience; if asked whether you are AI, answer honestly and briefly.',
            '- Before sending, delete empty praise ("отличный выбор", "идеально подходит", "хорошие характеристики")',
            '  and generic closing invitations ("Хотите узнать подробнее или оформить заказ?", "Как вы хотите поступить?",',
            '  "Would you like to know more?", "let me know", "ба ман хабар диҳед"). Replace praise with a specific',
            '  supported reason only when recommending. Do not replace a deleted invitation with a different invitation.',
            '- When the customer thanks you or wants to think, acknowledge it in ONE short sentence and stop.',
            '  Do not add "if you have questions", an offer to help, a question, or another product.',
        ]);
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
                '- After answering, take initiative to move the sale forward: offer ONE relevant next step — either a single related item FROM THE KNOWLEDGE BASE or catalog tool results, or an offer to place the order / book a time.',
                '- Never invent products, prices, or promotions; only suggest items that appear in the KNOWLEDGE BASE or catalog tool results.',
                '- Still at most one suggestion and one question. Stay warm and helpful — never pushy, and never repeat an offer the customer already turned down.',
            ]),
            default => implode("\n", [ // subtle
                'Proactivity: SUBTLE.',
                '- Answer the current request and normally stop. Suggest a next step only when the customer indicates readiness or asks for advice; do not add a generic invitation to every answer.',
                '- Any product you mention must come FROM THE KNOWLEDGE BASE or catalog tool results. Never invent items and never push a sale.',
                '- If a plain answer is enough, just answer — do not force a suggestion.',
            ]),
        };
    }

    /**
     * What language to write the reply in.
     *
     * On 'auto' the language is DETECTED and named outright rather than left to
     * the model to infer. "Reply in the customer's language" is enough for
     * Russian and English and is not enough for Tajik: Tajik is written in
     * Cyrillic, so a short Tajik message inside a mostly-Russian conversation
     * reads to the model as unusual Russian, and it answers in Russian. Naming
     * the language removes the inference — see LanguageDetector.
     *
     * The owner's explicit setting still wins outright. Someone who pinned their
     * agent to Russian meant it, whatever a customer types.
     */
    protected function languageInstruction(Agent $agent, Conversation $conversation): string
    {
        try {
            $detected = $this->languages->forConversation($this->recentCustomerMessages($conversation));
        } catch (Throwable $e) {
            // Choosing a language is an optimisation over an instruction that
            // already works without it. It must never be the reason a customer
            // gets no answer at all, so a failure here falls through to the
            // "reply in whatever they used" wording below.
            Log::warning('Language detection failed; falling back to inferred language', [
                'agent_id' => $agent->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            $detected = null;
        }

        if ($detected !== null) {
            $name = self::LANGUAGE_NAMES[$detected] ?? $detected;

            return implode(' ', [
                "The customer is writing in {$name}. WRITE YOUR REPLY IN {$name}.",
                $detected === LanguageDetector::TAJIK
                    // Named because this is the case that was going wrong, and
                    // because the two languages share an alphabet: without it,
                    // "reply in Tajik" is satisfied by Russian that looks close
                    // enough, and a customer writing Tajik keeps getting Russian.
                    ? 'Tajik and Russian share the Cyrillic alphabet, and this customer is writing TAJIK, not Russian. '
                        .'Answer in Tajik. Russian loanwords they used themselves (диск, заказ, ноутбук) are fine to '
                        .'keep — the sentence around them must be Tajik. '
                        // The second failure: Tajik sentences with English and
                        // pseudo-Russian filler spliced in — "дар нарх range
                        // васият", "дар буҷаатон фити кунад".
                        .'Write natural, everyday Tajik, the way a shop assistant in Khujand speaks it. Do NOT splice '
                        .'English or Russian words, or half-translated hybrids, into a Tajik sentence: no "range", "fit", '
                        .'"budget", no "фити кунад", no "нарх range". Say it in Tajik: "дар ҳудуди буҷаи шумо", '
                        .'"ба буҷаи шумо мувофиқ аст", "тақрибан", "наздиктарин". Product names, brands, model numbers, '
                        .'specifications and prices stay exactly as the catalog writes them; every '
                        .'other word must be Tajik. Before sending, check each word that is not a product name, and '
                        .'replace any English or Russian one with its Tajik equivalent. Prefer simple, short sentences '
                        .'with familiar words over ornate sales language or literal translations of English idioms.'
                    : 'Do not switch to another language unless the customer does.',
                'If they switch language later, switch with them on that message.',
            ]);
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
     * The customer's own recent messages, newest first.
     *
     * Newest first because the newest determinate one wins: a message with no
     * language of its own ("ок", a photo, a model number) must inherit rather
     * than reset the conversation to the default.
     *
     * @return Collection<int, string>
     */
    protected function recentCustomerMessages(Conversation $conversation): Collection
    {
        return $conversation->messages()
            ->where('role', 'user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->take(self::LANGUAGE_LOOKBACK)
            ->pluck('content');
    }

    /**
     * How to pivot when a TIER 2 business fact is missing. This picks the shape
     * of the pivot; it never licenses a bare refusal, and it does not apply to
     * TIER 1 general questions — those are answered, not deflected.
     *
     * The owner's own fallback_message is handed over as a TONE sample, never as
     * a line to send. It used to arrive as "you may phrase it close to: ...",
     * and a model given a ready-made sentence naturally sent that sentence — the
     * same one, word for word, every time a customer asked something the catalog
     * or the knowledge base did not cover. From the customer's side that is the
     * bot repeating itself, which is exactly what a human-sounding assistant
     * must not do. So the sample is framed as register and length to imitate,
     * and repeating any earlier reply is forbidden outright.
     */
    protected function fallbackInstruction(string $onNotFound, ?string $fallbackMessage): string
    {
        $instruction = 'When a TIER 2 fact about this business is missing from the KNOWLEDGE BASE, do not guess, '
            .'and do not end the conversation there. ';

        $instruction .= match ($onNotFound) {
            'human_handoff' => 'Say briefly which detail needs confirmation and offer to pass the question to a colleague. Only claim it was passed on after the corresponding tool succeeds. Offer to take their contact if you do not already have it.',
            'suggest_alternatives' => 'Say briefly that you cannot confirm that one, then offer the closest relevant things that ARE in the KNOWLEDGE BASE (at most 2) and ask if either helps.',
            default => 'Say briefly which detail you cannot confirm. Answer any part you can, then offer a relevant next step if available. Do not promise to check and come back unless that follow-up is actually recorded.',
        };

        $instruction .= ' Write it fresh each time, about what THIS message asked and what this conversation has '
            .'already established. Never send a stock sentence, and never repeat — or barely reword — something you '
            .'have already said in this conversation; if you are pivoting for the second time, it has to say '
            .'something new and take the conversation somewhere.';

        if (! empty($fallbackMessage)) {
            $instruction .= ' For tone only — the owner would phrase a pivot roughly like this: '
                ."\"{$fallbackMessage}\". Match its register and length. Do NOT send it as written, and do not send "
                .'the same variation of it twice.';
        }

        return $instruction;
    }
}
