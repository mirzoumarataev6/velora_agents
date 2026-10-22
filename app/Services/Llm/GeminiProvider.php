<?php

namespace App\Services\Llm;

use App\Exceptions\GeminiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiProvider implements LlmProviderInterface
{
    protected string $apiKey;

    protected string $baseUrl;

    protected string $chatModel;

    protected string $embeddingModel;

    protected string $visionModel;

    protected ?int $embeddingDimensions;

    protected int $timeout;

    protected int $connectTimeout;

    protected int $retryTimes;

    protected int $retryDelayMs;

    protected int $embeddingBatchSize;

    public function __construct(protected UsageRecorder $usage)
    {
        $this->apiKey = (string) config('agent.gemini.api_key');
        $this->baseUrl = (string) config('agent.gemini.base_url');
        $this->chatModel = (string) config('agent.gemini.chat_model');
        $this->embeddingModel = (string) config('agent.gemini.embedding_model');
        $this->visionModel = (string) config('agent.gemini.vision_model');
        $this->embeddingDimensions = config('agent.gemini.embedding_dimensions')
            ? (int) config('agent.gemini.embedding_dimensions')
            : null;
        $this->timeout = (int) config('agent.gemini.timeout');
        $this->connectTimeout = (int) config('agent.gemini.connect_timeout');
        $this->retryTimes = (int) config('agent.gemini.retry_times');
        $this->retryDelayMs = (int) config('agent.gemini.retry_delay_ms');
        $this->embeddingBatchSize = (int) config('agent.gemini.embedding_batch_size');
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function embeddingDimensions(): ?int
    {
        return $this->embeddingDimensions;
    }

    public function embeddingModel(): string
    {
        return $this->embeddingModel;
    }

    public function generate(string $systemInstruction, array $contents, array $options = []): array
    {
        $payload = [
            'systemInstruction' => [
                'parts' => [['text' => $systemInstruction]],
            ],
            'contents' => array_map(fn (array $turn) => $this->toGeminiContent($turn), $contents),
        ];

        if (! empty($options['tools'])) {
            $payload['tools'] = [[
                'functionDeclarations' => array_map(fn (array $tool) => array_filter([
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['parameters'] ?? null,
                ], fn ($value) => $value !== null), $options['tools']),
            ]];
        }

        $generationConfig = array_filter([
            'temperature' => $options['temperature'] ?? null,
            'maxOutputTokens' => $options['max_output_tokens'] ?? null,
        ], fn ($value) => $value !== null);

        if (array_key_exists('thinking_budget', $options)) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => $options['thinking_budget']];
        }

        if ($generationConfig !== []) {
            $payload['generationConfig'] = $generationConfig;
        }

        $data = $this->request('generateContent', "/models/{$this->chatModel}:generateContent", $payload);

        $candidates = $data['candidates'] ?? [];

        if ($candidates === []) {
            $blockReason = $data['promptFeedback']['blockReason'] ?? 'unknown';
            throw new GeminiException("Gemini generateContent returned no candidates (blockReason: {$blockReason})");
        }

        $parts = $candidates[0]['content']['parts'] ?? [];
        $text = collect($parts)->pluck('text')->filter()->implode('');

        $toolCalls = [];
        foreach ($parts as $part) {
            if (! isset($part['functionCall'])) {
                continue;
            }

            $call = $part['functionCall'];
            $toolCalls[] = [

                'id' => (string) ($call['name'] ?? 'call'),
                'name' => (string) ($call['name'] ?? ''),
                'arguments' => is_array($call['args'] ?? null) ? $call['args'] : [],
            ];
        }

        $result = [
            'text' => $text,
            'input_tokens' => (int) ($data['usageMetadata']['promptTokenCount'] ?? 0),
            'output_tokens' => (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0),
            'finish_reason' => $candidates[0]['finishReason'] ?? null,
            'tool_calls' => $toolCalls,
        ];

        $this->usage->record(
            $this->name(),
            'generateContent',
            UsageRecorder::TYPE_GENERATION,
            $result['input_tokens'],
            $result['output_tokens']
        );

        return $result;
    }

    protected function toGeminiContent(array $turn): array
    {
        return match ($turn['role']) {
            'tool_call' => [
                'role' => 'model',
                'parts' => [['functionCall' => [
                    'name' => $turn['name'],
                    'args' => (object) ($turn['arguments'] ?? []),
                ]]],
            ],
            'tool_result' => [
                'role' => 'user',
                'parts' => [['functionResponse' => [
                    'name' => $turn['name'],
                    'response' => ['result' => (string) ($turn['result'] ?? '')],
                ]]],
            ],
            default => [
                'role' => $turn['role'],
                'parts' => [['text' => $turn['text']]],
            ],
        };
    }

    public function extractFromImage(string $absolutePath, string $prompt, ?string $mimeType = null): array
    {
        if (! is_readable($absolutePath)) {
            throw new GeminiException("Image not readable: {$absolutePath}");
        }

        $mimeType ??= mime_content_type($absolutePath) ?: 'application/octet-stream';
        $base64 = base64_encode(file_get_contents($absolutePath));

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $prompt],
                        ['inlineData' => ['mimeType' => $mimeType, 'data' => $base64]],
                    ],
                ],
            ],
        ];

        $data = $this->request('generateContent(vision)', "/models/{$this->visionModel}:generateContent", $payload);

        $candidates = $data['candidates'] ?? [];

        if ($candidates === []) {
            $blockReason = $data['promptFeedback']['blockReason'] ?? 'unknown';
            throw new GeminiException("Gemini vision extraction returned no candidates (blockReason: {$blockReason})");
        }

        $parts = $candidates[0]['content']['parts'] ?? [];
        $text = collect($parts)->pluck('text')->filter()->implode('');

        $result = [
            'text' => $text,
            'input_tokens' => (int) ($data['usageMetadata']['promptTokenCount'] ?? 0),
            'output_tokens' => (int) ($data['usageMetadata']['candidatesTokenCount'] ?? 0),
        ];

        $this->usage->record(
            $this->name(),
            'generateContent(vision)',
            UsageRecorder::TYPE_GENERATION,
            $result['input_tokens'],
            $result['output_tokens']
        );

        return $result;
    }

    public function embed(string $text): array
    {
        $payload = [
            'content' => ['parts' => [['text' => $text]]],
        ];

        if ($this->embeddingDimensions) {
            $payload['outputDimensionality'] = $this->embeddingDimensions;
        }

        $data = $this->request('embedContent', "/models/{$this->embeddingModel}:embedContent", $payload);

        $vector = $data['embedding']['values'] ?? null;

        if (! is_array($vector)) {
            throw new GeminiException('Gemini embedContent returned no embedding values');
        }

        $inputTokens = $this->estimateTokens($text);
        $this->usage->record($this->name(), 'embedContent', UsageRecorder::TYPE_EMBEDDING, $inputTokens, 0);

        return ['vector' => $vector, 'input_tokens' => $inputTokens];
    }

    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $results = [];

        foreach (array_chunk($texts, max(1, $this->embeddingBatchSize), true) as $chunk) {
            $requests = [];
            foreach ($chunk as $text) {
                $request = [
                    'model' => "models/{$this->embeddingModel}",
                    'content' => ['parts' => [['text' => $text]]],
                ];

                if ($this->embeddingDimensions) {
                    $request['outputDimensionality'] = $this->embeddingDimensions;
                }

                $requests[] = $request;
            }

            $data = $this->request(
                'batchEmbedContents',
                "/models/{$this->embeddingModel}:batchEmbedContents",
                ['requests' => $requests]
            );

            $embeddings = $data['embeddings'] ?? null;

            if (! is_array($embeddings) || count($embeddings) !== count($chunk)) {
                throw new GeminiException('Gemini batchEmbedContents returned a mismatched number of embeddings');
            }

            $chunkKeys = array_keys($chunk);
            $totalInputTokens = 0;

            foreach (array_values($embeddings) as $i => $embedding) {
                $key = $chunkKeys[$i];
                $inputTokens = $this->estimateTokens($chunk[$key]);
                $totalInputTokens += $inputTokens;

                $results[$key] = [
                    'vector' => $embedding['values'] ?? [],
                    'input_tokens' => $inputTokens,
                ];
            }

            $this->usage->record(
                $this->name(),
                'batchEmbedContents',
                UsageRecorder::TYPE_EMBEDDING,
                $totalInputTokens,
                0
            );
        }

        ksort($results);

        return $results;
    }

    public function estimateTokens(string $text): int
    {
        return max(1, (int) ceil(mb_strlen($text) / 4));
    }

    protected function request(string $operation, string $path, array $payload): array
    {
        if (empty($this->apiKey)) {
            throw new GeminiException('GEMINI_API_KEY is not configured');
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->retry(
                    $this->retryTimes,
                    fn (int $attempt) => $this->retryDelayMs * (2 ** ($attempt - 1)),
                    function (\Throwable $exception) {
                        if ($exception instanceof ConnectionException) {
                            return true;
                        }

                        if ($exception instanceof RequestException) {

                            return in_array($exception->response->status(), [408, 500, 502, 503, 504], true);
                        }

                        return false;
                    },
                    throw: false,
                )
                ->withHeaders([
                    'x-goog-api-key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($path, $payload);
        } catch (ConnectionException $e) {
            Log::warning("Gemini {$operation} connection failure", ['message' => $e->getMessage()]);

            throw GeminiException::fromThrowable($operation, $e);
        }

        if ($response->failed()) {
            Log::warning("Gemini {$operation} request failed", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw GeminiException::fromResponse($operation, $response->status(), $response->body());
        }

        return $response->json() ?? [];
    }
}
