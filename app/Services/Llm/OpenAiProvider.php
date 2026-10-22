<?php

namespace App\Services\Llm;

use App\Exceptions\OpenAiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiProvider implements LlmProviderInterface
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
        $this->apiKey = (string) config('agent.openai.api_key');
        $this->baseUrl = (string) config('agent.openai.base_url');
        $this->chatModel = (string) config('agent.openai.chat_model');
        $this->embeddingModel = (string) config('agent.openai.embedding_model');
        $this->visionModel = (string) config('agent.openai.vision_model');
        $this->embeddingDimensions = config('agent.openai.embedding_dimensions')
            ? (int) config('agent.openai.embedding_dimensions')
            : null;
        $this->timeout = (int) config('agent.openai.timeout');
        $this->connectTimeout = (int) config('agent.openai.connect_timeout');
        $this->retryTimes = (int) config('agent.openai.retry_times');
        $this->retryDelayMs = (int) config('agent.openai.retry_delay_ms');
        $this->embeddingBatchSize = (int) config('agent.openai.embedding_batch_size');
    }

    public function name(): string
    {
        return 'openai';
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
        $messages = [['role' => 'system', 'content' => $systemInstruction]];

        foreach ($contents as $turn) {
            $messages[] = $this->toOpenAiMessage($turn);
        }

        $payload = array_filter([
            'model' => $this->chatModel,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? null,
            'max_tokens' => $options['max_output_tokens'] ?? null,
        ], fn ($value) => $value !== null);

        if (! empty($options['tools'])) {
            $payload['tools'] = array_map(fn (array $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => (object) []],
                ],
            ], $options['tools']);

            if (isset($options['tool_choice'])) {
                $payload['tool_choice'] = $options['tool_choice'];
            }
        }

        $data = $this->request('chat.completions', '/chat/completions', $payload);

        $result = $this->readCompletion($data, 'chat.completions');

        $this->usage->record(
            $this->name(),
            'chat.completions',
            UsageRecorder::TYPE_GENERATION,
            $result['input_tokens'],
            $result['output_tokens']
        );

        return $result;
    }

    public function extractFromImage(string $absolutePath, string $prompt, ?string $mimeType = null): array
    {
        if (! is_readable($absolutePath)) {
            throw new OpenAiException("Image not readable: {$absolutePath}");
        }

        $mimeType ??= mime_content_type($absolutePath) ?: 'application/octet-stream';

        if (! str_starts_with($mimeType, 'image/')) {
            throw new OpenAiException(
                "OpenAI vision extraction supports image/* only, got {$mimeType}"
            );
        }

        $base64 = base64_encode(file_get_contents($absolutePath));

        $payload = [
            'model' => $this->visionModel,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]],
                ],
            ]],
        ];

        $data = $this->request('chat.completions(vision)', '/chat/completions', $payload);

        $result = $this->readCompletion($data, 'chat.completions(vision)');

        $this->usage->record(
            $this->name(),
            'chat.completions(vision)',
            UsageRecorder::TYPE_GENERATION,
            $result['input_tokens'],
            $result['output_tokens']
        );

        return [
            'text' => $result['text'],
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
        ];
    }

    public function embed(string $text): array
    {
        $results = $this->embedBatch([$text]);

        if (! isset($results[0])) {
            throw new OpenAiException('OpenAI embeddings returned no embedding values');
        }

        return $results[0];
    }

    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $results = [];

        foreach (array_chunk($texts, max(1, $this->embeddingBatchSize), true) as $chunk) {
            $payload = array_filter([
                'model' => $this->embeddingModel,
                'input' => array_values($chunk),
                'dimensions' => $this->embeddingDimensions,
            ], fn ($value) => $value !== null);

            $data = $this->request('embeddings', '/embeddings', $payload);

            $embeddings = $data['data'] ?? null;

            if (! is_array($embeddings) || count($embeddings) !== count($chunk)) {
                throw new OpenAiException('OpenAI embeddings returned a mismatched number of embeddings');
            }

            $chunkKeys = array_keys($chunk);
            $totalInputTokens = 0;

            foreach ($embeddings as $embedding) {

                $position = is_array($embedding) && isset($embedding['index'])
                    ? (int) $embedding['index']
                    : null;

                if ($position === null || ! isset($chunkKeys[$position])) {
                    throw new OpenAiException('OpenAI embeddings returned an unusable index');
                }

                $key = $chunkKeys[$position];
                $vector = $embedding['embedding'] ?? null;

                if (! is_array($vector)) {
                    throw new OpenAiException('OpenAI embeddings returned no embedding values');
                }

                $inputTokens = $this->estimateTokens($chunk[$key]);
                $totalInputTokens += $inputTokens;

                $results[$key] = ['vector' => $vector, 'input_tokens' => $inputTokens];
            }

            $this->usage->record(
                $this->name(),
                'embeddings',
                UsageRecorder::TYPE_EMBEDDING,
                (int) ($data['usage']['prompt_tokens'] ?? $totalInputTokens),
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

    protected function toOpenAiMessage(array $turn): array
    {
        return match ($turn['role']) {
            'tool_call' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => $turn['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $turn['name'],
                        'arguments' => json_encode($turn['arguments'] ?? [], JSON_UNESCAPED_UNICODE),
                    ],
                ]],
            ],
            'tool_result' => [
                'role' => 'tool',
                'tool_call_id' => $turn['id'],
                'content' => (string) ($turn['result'] ?? ''),
            ],

            'model' => ['role' => 'assistant', 'content' => $turn['text']],
            default => ['role' => 'user', 'content' => $turn['text']],
        };
    }

    protected function readCompletion(array $data, string $operation): array
    {
        $choices = $data['choices'] ?? [];

        if (! is_array($choices) || $choices === []) {
            throw new OpenAiException("OpenAI {$operation} returned no choices");
        }

        $message = $choices[0]['message'] ?? [];
        $content = $message['content'] ?? null;

        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $call) {
            if (($call['type'] ?? 'function') !== 'function') {
                continue;
            }

            $arguments = json_decode($call['function']['arguments'] ?? '{}', true);

            $toolCalls[] = [
                'id' => (string) ($call['id'] ?? ''),
                'name' => (string) ($call['function']['name'] ?? ''),
                'arguments' => is_array($arguments) ? $arguments : [],
            ];
        }

        return [
            'text' => is_string($content) ? $content : '',
            'input_tokens' => (int) ($data['usage']['prompt_tokens'] ?? 0),
            'output_tokens' => (int) ($data['usage']['completion_tokens'] ?? 0),
            'finish_reason' => $choices[0]['finish_reason'] ?? null,
            'tool_calls' => $toolCalls,
        ];
    }

    protected function request(string $operation, string $path, array $payload): array
    {
        if (empty($this->apiKey)) {
            throw new OpenAiException('OPENAI_API_KEY is not configured');
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->retry(
                    $this->retryTimes,
                    fn (int $attempt) => $this->retryDelayMs * (2 ** ($attempt - 1)),
                    fn (\Throwable $exception) => $this->shouldRetry($exception),
                    throw: false,
                )
                ->withHeaders([
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($path, $payload);
        } catch (ConnectionException $e) {
            Log::warning("OpenAI {$operation} connection failure", ['message' => $e->getMessage()]);

            throw OpenAiException::fromThrowable($operation, $e);
        }

        if ($response->failed()) {
            Log::warning("OpenAI {$operation} request failed", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw OpenAiException::fromResponse($operation, $response->status(), $response->body());
        }

        return $response->json() ?? [];
    }

    protected function shouldRetry(\Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        if ($status === 429) {
            return ! $this->isQuotaExhausted($exception->response->json());
        }

        return in_array($status, [408, 500, 502, 503, 504], true);
    }

    protected function isQuotaExhausted(mixed $body): bool
    {
        if (! is_array($body)) {
            return false;
        }

        $type = $body['error']['type'] ?? null;
        $code = $body['error']['code'] ?? null;

        return $type === 'insufficient_quota' || $code === 'insufficient_quota';
    }
}
