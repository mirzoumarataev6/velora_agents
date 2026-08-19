<?php

namespace Tests\Feature\Llm;

use App\Exceptions\OpenAiException;
use App\Services\Llm\OpenAiProvider;
use App\Services\Llm\UsageRecorder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'agent.openai.api_key' => 'test-key',
            'agent.openai.base_url' => 'https://api.openai.com/v1',
            'agent.openai.chat_model' => 'gpt-4o-mini',
            'agent.openai.embedding_model' => 'text-embedding-3-small',
            'agent.openai.vision_model' => 'gpt-4o-mini',
            'agent.openai.embedding_dimensions' => 1536,
            'agent.openai.timeout' => 5,
            'agent.openai.connect_timeout' => 5,
            'agent.openai.retry_times' => 3,
            // Keep backoff out of the test runtime.
            'agent.openai.retry_delay_ms' => 1,
            'agent.openai.embedding_batch_size' => 100,
        ]);
    }

    protected function provider(): OpenAiProvider
    {
        return new OpenAiProvider(new UsageRecorder);
    }

    public function test_it_generates_a_chat_completion(): void
    {
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Hello there!'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 4],
            ]),
        ]);

        $result = $this->provider()->generate('You are terse.', [
            ['role' => 'user', 'text' => 'Hi'],
        ]);

        $this->assertSame('Hello there!', $result['text']);
        $this->assertSame(12, $result['input_tokens']);
        $this->assertSame(4, $result['output_tokens']);
        $this->assertSame('stop', $result['finish_reason']);

        // The Gemini-vocabulary "model" role must be translated to "assistant",
        // and the system instruction sent as a leading system message.
        Http::assertSent(function ($request) {
            $messages = $request['messages'];

            return $request['model'] === 'gpt-4o-mini'
                && $messages[0] === ['role' => 'system', 'content' => 'You are terse.']
                && $messages[1] === ['role' => 'user', 'content' => 'Hi'];
        });
    }

    public function test_it_translates_the_model_role_to_assistant(): void
    {
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ]),
        ]);

        $this->provider()->generate('sys', [
            ['role' => 'user', 'text' => 'first'],
            ['role' => 'model', 'text' => 'a prior reply'],
            ['role' => 'user', 'text' => 'second'],
        ]);

        Http::assertSent(function ($request) {
            $roles = array_column($request['messages'], 'role');

            return $roles === ['system', 'user', 'assistant', 'user'];
        });
    }

    public function test_it_sends_tool_definitions_and_parses_tool_calls(): void
    {
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_abc',
                            'type' => 'function',
                            'function' => ['name' => 'create_lead', 'arguments' => '{"contact":"+992001","items":"Куртка"}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 8],
            ]),
        ]);

        $result = $this->provider()->generate('sys', [['role' => 'user', 'text' => 'Запишите куртку']], [
            'tools' => [[
                'name' => 'create_lead',
                'description' => 'Record a lead',
                'parameters' => ['type' => 'object', 'properties' => ['contact' => ['type' => 'string']]],
            ]],
        ]);

        $this->assertCount(1, $result['tool_calls']);
        $this->assertSame('call_abc', $result['tool_calls'][0]['id']);
        $this->assertSame('create_lead', $result['tool_calls'][0]['name']);
        $this->assertSame(['contact' => '+992001', 'items' => 'Куртка'], $result['tool_calls'][0]['arguments']);

        Http::assertSent(function ($request) {
            $tool = $request['tools'][0] ?? null;

            return $tool
                && $tool['type'] === 'function'
                && $tool['function']['name'] === 'create_lead';
        });
    }

    public function test_it_translates_tool_call_and_tool_result_turns(): void
    {
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Готово!'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2],
            ]),
        ]);

        $this->provider()->generate('sys', [
            ['role' => 'user', 'text' => 'Запишите куртку'],
            ['role' => 'tool_call', 'id' => 'call_1', 'name' => 'create_lead', 'arguments' => ['items' => 'Куртка']],
            ['role' => 'tool_result', 'id' => 'call_1', 'name' => 'create_lead', 'result' => 'Lead saved.'],
        ]);

        Http::assertSent(function ($request) {
            $messages = $request['messages'];

            return $messages[1] === ['role' => 'user', 'content' => 'Запишите куртку']
                && $messages[2]['role'] === 'assistant'
                && $messages[2]['tool_calls'][0]['id'] === 'call_1'
                && $messages[2]['tool_calls'][0]['function']['name'] === 'create_lead'
                && $messages[3] === ['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => 'Lead saved.'];
        });
    }

    public function test_a_plain_completion_returns_an_empty_tool_calls_list(): void
    {
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'hi'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            ]),
        ]);

        $result = $this->provider()->generate('sys', [['role' => 'user', 'text' => 'hi']]);

        $this->assertSame([], $result['tool_calls']);
    }

    public function test_it_embeds_a_single_text(): void
    {
        $vector = array_fill(0, 1536, 0.01);

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['index' => 0, 'embedding' => $vector]],
                'usage' => ['prompt_tokens' => 7],
            ]),
        ]);

        $result = $this->provider()->embed('Red cotton dress, size M.');

        $this->assertCount(1536, $result['vector']);
        $this->assertSame(0.01, $result['vector'][0]);
        $this->assertGreaterThan(0, $result['input_tokens']);

        Http::assertSent(fn ($request) => $request['model'] === 'text-embedding-3-small'
            && $request['dimensions'] === 1536
            && $request['input'] === ['Red cotton dress, size M.']);
    }

    public function test_it_reorders_batch_embeddings_by_their_index(): void
    {
        // The API may return items out of order; the per-item index is authoritative.
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    ['index' => 1, 'embedding' => [0.2]],
                    ['index' => 0, 'embedding' => [0.1]],
                ],
                'usage' => ['prompt_tokens' => 4],
            ]),
        ]);

        $result = $this->provider()->embedBatch(['first', 'second']);

        $this->assertSame([0.1], $result[0]['vector']);
        $this->assertSame([0.2], $result[1]['vector']);
    }

    public function test_it_retries_a_transient_rate_limit_then_succeeds(): void
    {
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::sequence()
                // A burst rate limit (no insufficient_quota) — worth retrying.
                ->push(['error' => ['type' => 'rate_limit_exceeded', 'message' => 'slow down']], 429)
                ->push([
                    'choices' => [['message' => ['content' => 'recovered'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2],
                ], 200),
        ]);

        $result = $this->provider()->generate('sys', [['role' => 'user', 'text' => 'hi']]);

        $this->assertSame('recovered', $result['text']);
        Http::assertSentCount(2);
    }

    public function test_quota_exhaustion_is_not_retried_and_raises(): void
    {
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'error' => ['type' => 'insufficient_quota', 'message' => 'You exceeded your quota'],
            ], 429),
        ]);

        try {
            $this->provider()->generate('sys', [['role' => 'user', 'text' => 'hi']]);
            $this->fail('Expected OpenAiException on quota exhaustion.');
        } catch (OpenAiException $e) {
            $this->assertStringContainsString('429', $e->getMessage());
        }

        // A spent quota won't clear within a backoff window: no retries.
        Http::assertSentCount(1);
    }

    public function test_malformed_chat_response_raises(): void
    {
        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response([
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 0],
                // no 'choices'
            ]),
        ]);

        $this->expectException(OpenAiException::class);
        $this->provider()->generate('sys', [['role' => 'user', 'text' => 'hi']]);
    }

    public function test_malformed_embedding_response_raises(): void
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                // count mismatch: asked for one, got zero
                'data' => [],
                'usage' => ['prompt_tokens' => 1],
            ]),
        ]);

        $this->expectException(OpenAiException::class);
        $this->provider()->embed('anything');
    }

    public function test_missing_api_key_raises_without_calling_the_api(): void
    {
        config(['agent.openai.api_key' => '']);
        Http::fake();

        try {
            $this->provider()->embed('x');
            $this->fail('Expected OpenAiException when the key is unconfigured.');
        } catch (OpenAiException $e) {
            $this->assertStringContainsString('OPENAI_API_KEY', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_vision_rejects_non_image_mime_types(): void
    {
        Http::fake();
        $tmp = tempnam(sys_get_temp_dir(), 'oai_pdf_');
        file_put_contents($tmp, '%PDF-1.4 fake');

        try {
            $this->provider()->extractFromImage($tmp, 'describe', 'application/pdf');
            $this->fail('Expected OpenAiException for a non-image mime type.');
        } catch (OpenAiException $e) {
            $this->assertStringContainsString('image/', $e->getMessage());
        } finally {
            @unlink($tmp);
        }

        Http::assertNothingSent();
    }
}
