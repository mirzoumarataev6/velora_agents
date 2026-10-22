<?php

namespace App\Services\Llm;

use App\Exceptions\OllamaException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OllamaProvider implements LlmProviderInterface
{
    protected string $baseUrl;

    protected string $chatModel;

    protected string $embeddingModel;

    protected int $timeout;

    protected int $connectTimeout;

    protected int $retryTimes;

    protected int $retryDelayMs;

    protected int $embeddingBatchSize;

    public function __construct(protected UsageRecorder $usage)
    {
        $this->baseUrl = rtrim((string) config('agent.ollama.base_url'), '/');
        $this->chatModel = (string) config('agent.ollama.chat_model');
        $this->embeddingModel = (string) config('agent.ollama.embedding_model');
        $this->timeout = (int) config('agent.ollama.timeout');
        $this->connectTimeout = (int) config('agent.ollama.connect_timeout');
        $this->retryTimes = (int) config('agent.ollama.retry_times');
        $this->retryDelayMs = (int) config('agent.ollama.retry_delay_ms');
        $this->embeddingBatchSize = (int) config('agent.ollama.embedding_batch_size');
    }

    public function name(): string
    {
        return 'ollama';
    }

    public function embeddingDimensions(): ?int
    {

        return null;
    }

    public function embeddingModel(): string
    {
        return $this->embeddingModel;
    }

    public function generate(string $systemInstruction, array $contents, array $options = []): array
    {
        $messages = [['role' => 'system', 'content' => $systemInstruction]];
        foreach ($contents as $turn) {
            $messages[] = $this->toOllamaMessage($turn);
        }

        $payload = [
            'model' => $this->chatModel,
            'messages' => $messages,
            'stream' => false,
        ];

        $generationOptions = array_filter([
            'temperature' => $options['temperature'] ?? null,
            'num_predict' => $options['max_output_tokens'] ?? null,
        ], fn ($value) => $value !== null);

        if ($generationOptions !== []) {
            $payload['options'] = $generationOptions;
        }

        if (! empty($options['tools'])) {
            $payload['tools'] = array_map(fn (array $tool) => [
                'type' => 'function',
                'function' => [
                    'name' => $tool['name'],
                    'description' => $tool['description'] ?? '',
                    'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => (object) []],
                ],
            ], $options['tools']);
        }

        $data = $this->request('chat', '/api/chat', $payload);
        $result = $this->readChatResponse($data);

        $this->usage->record(
            $this->name(), 'chat', UsageRecorder::TYPE_GENERATION,
            $result['input_tokens'], $result['output_tokens']
        );

        return $result;
    }

    public function extractFromImage(string $absolutePath, string $prompt, ?string $mimeType = null): array
    {
        if (! is_readable($absolutePath)) {
            throw new OllamaException("Image not readable: {$absolutePath}");
        }

        $base64 = base64_encode(file_get_contents($absolutePath));

        $payload = [
            'model' => $this->chatModel,
            'messages' => [[
                'role' => 'user',
                'content' => $prompt,
                'images' => [$base64],
            ]],
            'stream' => false,
        ];

        $data = $this->request('chat(vision)', '/api/chat', $payload);
        $result = $this->readChatResponse($data);

        $this->usage->record(
            $this->name(), 'chat(vision)', UsageRecorder::TYPE_GENERATION,
            $result['input_tokens'], $result['output_tokens']
        );

        return [
            'text' => $result['text'],
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
        ];
    }

    public function embed(string $text): array
    {
        $data = $this->request('embeddings', '/api/embeddings', [
            'model' => $this->embeddingModel,
            'prompt' => $text,
        ]);

        $vector = $data['embedding'] ?? null;

        if (! is_array($vector) || $vector === []) {
            throw new OllamaException('Ollama embeddings returned no embedding values');
        }

        $inputTokens = $this->estimateTokens($text);
        $this->usage->record($this->name(), 'embeddings', UsageRecorder::TYPE_EMBEDDING, $inputTokens, 0);

        return ['vector' => $vector, 'input_tokens' => $inputTokens];
    }

    public function embedBatch(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $results = [];

        foreach (array_chunk($texts, max(1, $this->embeddingBatchSize), true) as $chunk) {
            $totalInputTokens = 0;

            foreach ($chunk as $key => $text) {
                $embedded = $this->embed($text);
                $results[$key] = $embedded;
                $totalInputTokens += $embedded['input_tokens'];
            }

            $this->usage->record($this->name(), 'embeddings(batch)', UsageRecorder::TYPE_EMBEDDING, $totalInputTokens, 0);
        }

        ksort($results);

        return $results;
    }

    public function estimateTokens(string $text): int
    {
        return max(1, (int) ceil(mb_strlen($text) / 4));
    }

    protected function toOllamaMessage(array $turn): array
    {
        return match ($turn['role']) {
            'tool_call' => [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [[
                    'function' => [
                        'name' => $turn['name'],
                        'arguments' => $turn['arguments'] ?? [],
                    ],
                ]],
            ],
            'tool_result' => [
                'role' => 'tool',
                'content' => (string) ($turn['result'] ?? ''),
            ],
            'model' => ['role' => 'assistant', 'content' => $turn['text']],
            default => ['role' => 'user', 'content' => $turn['text']],
        };
    }

    protected function readChatResponse(array $data): array
    {
        $message = $data['message'] ?? [];
        $content = $message['content'] ?? '';

        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $i => $call) {
            $name = (string) ($call['function']['name'] ?? '');
            $arguments = $call['function']['arguments'] ?? [];

            $toolCalls[] = [

                'id' => $name !== '' ? $name.':'.$i : 'call_'.$i,
                'name' => $name,
                'arguments' => is_array($arguments) ? $arguments : [],
            ];
        }

        return [
            'text' => is_string($content) ? $content : '',
            'input_tokens' => (int) ($data['prompt_eval_count'] ?? 0),
            'output_tokens' => (int) ($data['eval_count'] ?? 0),
            'finish_reason' => $data['done_reason'] ?? null,
            'tool_calls' => $toolCalls,
        ];
    }

    protected function request(string $operation, string $path, array $payload): array
    {
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

                        return $exception instanceof RequestException
                            && in_array($exception->response->status(), [500, 502, 503, 504], true);
                    },
                    throw: false,
                )
                ->acceptJson()
                ->post($path, $payload);
        } catch (ConnectionException $e) {
            Log::warning("Ollama {$operation} connection failure", ['message' => $e->getMessage(), 'base_url' => $this->baseUrl]);

            throw OllamaException::unreachable($this->baseUrl, $e);
        }

        if ($response->failed()) {
            $body = $response->body();

            Log::warning("Ollama {$operation} request failed", ['status' => $response->status(), 'body' => $body]);

            if ($response->status() === 404 && str_contains($body, 'not found')) {
                throw OllamaException::modelNotFound($payload['model'] ?? $this->chatModel);
            }

            throw OllamaException::fromResponse($operation, $response->status(), $body);
        }

        return $response->json() ?? [];
    }
}
