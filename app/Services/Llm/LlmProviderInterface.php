<?php

namespace App\Services\Llm;

use App\Exceptions\LlmException;

/**
 * The contract every LLM provider implements: chat generation, vision
 * extraction, and embeddings.
 *
 * Implementations are intentionally unaware of application concepts (agents,
 * conversations, roles like "operator"). Callers normalize their data into the
 * shapes expected here. Turn roles are always "user" or "model" — providers
 * translate to their own vocabulary internally.
 */
interface LlmProviderInterface
{
    /**
     * Short machine name of this provider ("gemini", "openai"). Used to label
     * usage logs and to record which provider produced a stored embedding.
     */
    public function name(): string;

    /**
     * Dimensionality of vectors returned by embed()/embedBatch(), or null when
     * the provider's default is unconstrained.
     */
    public function embeddingDimensions(): ?int;

    /**
     * Identifier of the embedding model in use, stamped onto stored vectors so
     * the vector store can refuse to compare across models. Dimensionality
     * alone does not identify a vector space — text-embedding-3-small and
     * -3-large both produce 1536 dimensions at their configured default and are
     * entirely incomparable.
     */
    public function embeddingModel(): string;

    /**
     * Generate a chat response.
     *
     * Turns are normally {role: "user"|"model", text: string}. To support a
     * function-calling round-trip, two extra turn shapes are also accepted:
     *   - {role: "tool_call", id: string, name: string, arguments: array}   — a prior model request to call a tool
     *   - {role: "tool_result", id: string, name: string, result: string}   — the value your code returned for that call
     * Providers translate these into their own function-calling vocabulary.
     *
     * When $options['tools'] is present the model may respond with tool calls
     * instead of (or alongside) text; those are returned under 'tool_calls'.
     *
     * @param  array<int, array<string, mixed>>  $contents  Ordered turns (see shapes above).
     * @param  array{temperature?: float, max_output_tokens?: int, thinking_budget?: int, tools?: array<int, array{name: string, description?: string, parameters?: array}>, tool_choice?: mixed}  $options
     * @return array{text: string, input_tokens: int, output_tokens: int, finish_reason: ?string, tool_calls: array<int, array{id: string, name: string, arguments: array}>}
     *
     * @throws LlmException
     */
    public function generate(string $systemInstruction, array $contents, array $options = []): array;

    /**
     * Extract text/description from an image (or other natively understood
     * document) using the provider's vision model.
     *
     * @return array{text: string, input_tokens: int, output_tokens: int}
     *
     * @throws LlmException
     */
    public function extractFromImage(string $absolutePath, string $prompt, ?string $mimeType = null): array;

    /**
     * Embed a single piece of text.
     *
     * @return array{vector: array<int, float>, input_tokens: int}
     *
     * @throws LlmException
     */
    public function embed(string $text): array;

    /**
     * Embed many texts, batching as the provider allows.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array{vector: array<int, float>, input_tokens: int}> Same order/keys as $texts.
     *
     * @throws LlmException
     */
    public function embedBatch(array $texts): array;

    /**
     * Rough token estimate, used where the provider returns no usage metadata.
     */
    public function estimateTokens(string $text): int;
}
