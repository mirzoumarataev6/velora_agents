<?php

namespace Tests\Feature;

use App\Exceptions\LlmException;
use App\Models\Agent;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Services\AgentReplyService;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentReplyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_falls_back_to_configured_message_when_gemini_generation_fails(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->andThrow(new LlmException('upstream is down'));
        });

        $agent = Agent::factory()->create([
            'response_format' => [
                'fallback_message' => 'Уточню у менеджера и вернусь с ответом.',
                'use_emojis' => false,
            ],
        ]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $service = $this->app->make(AgentReplyService::class);
        $result = $service->reply($conversation, 'Do you have this in size L?');

        $this->assertSame('Уточню у менеджера и вернусь с ответом.', $result['assistant']->content);
        $this->assertTrue($result['assistant']->meta['failed']);
        $this->assertSame('user', $result['user']->role);
        $this->assertSame('Do you have this in size L?', $result['user']->content);
    }

    public function test_it_uses_default_fallback_when_no_custom_message_configured_and_gemini_fails(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->andThrow(new LlmException('timeout'));
        });

        $agent = Agent::factory()->create(['response_format' => []]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $service = $this->app->make(AgentReplyService::class);
        $result = $service->reply($conversation, 'Hello?');

        $this->assertNotEmpty($result['assistant']->content);
        $this->assertTrue($result['assistant']->meta['failed']);
    }

    public function test_system_prompt_signals_no_context_when_no_chunks_pass_threshold(): void
    {
        $captured = null;

        $this->mock(LlmProviderInterface::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')
                ->withArgs(function ($systemPrompt, $contents) use (&$captured) {
                    $captured = $systemPrompt;

                    return true;
                })
                ->andReturn(['text' => 'I do not have that information.', 'input_tokens' => 10, 'output_tokens' => 5, 'finish_reason' => 'STOP']);
        });

        // Agent has no knowledge sources/chunks at all, so retrieval is necessarily empty.
        $agent = Agent::factory()->create(['behavior' => ['on_not_found' => 'apologize']]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $service = $this->app->make(AgentReplyService::class);
        $service->reply($conversation, 'Do you sell winter boots?');

        $this->assertStringContainsString('KNOWLEDGE BASE: (empty', $captured);
        $this->assertStringContainsString("don't have that detail to hand", $captured);

        // An empty knowledge base still leaves general questions about the
        // field answerable — that is the whole point of the split.
        $this->assertStringContainsString('Tier 1 general questions', $captured);
    }

    /**
     * Roughly half of what customers ask is curiosity about the field rather
     * than a question about the business. Refusing those reads as a broken
     * product, so the prompt has to draw the line explicitly.
     */
    public function test_system_prompt_carries_the_two_tier_answering_policy(): void
    {
        $captured = $this->capturePrompt(
            Agent::factory()->create(),
            'что такое бэкенд?'
        );

        $this->assertStringContainsString('TIER 1', $captured);
        $this->assertStringContainsString('TIER 2', $captured);

        // Tier 1: general knowledge of the field is licensed, explicitly.
        $this->assertStringContainsString('ANSWER THESE. Use your own general knowledge.', $captured);

        // Tier 2: business facts stay pinned to the knowledge base.
        $this->assertStringContainsString('may come ONLY from the KNOWLEDGE BASE', $captured);
        $this->assertStringContainsString('Never invent, estimate, or infer them', $captured);

        // And a bare refusal is never an acceptable reply on its own.
        $this->assertStringContainsString('Never reply with a bare refusal', $captured);
    }

    /**
     * The domain the agent is expert in has to come from the agent itself, so
     * that the same code makes a school's assistant knowledgeable about
     * programming and a shop's about fabrics. Nothing here names a vertical.
     */
    public function test_domain_expertise_is_derived_from_the_agent_not_hardcoded(): void
    {
        $captured = $this->capturePrompt(
            Agent::factory()->create(['description' => 'Учебный центр программирования в Худжанде']),
            'сложно ли учить Python?'
        );

        $this->assertStringContainsString('Учебный центр программирования в Худжанде', $captured);
        $this->assertStringContainsString('Infer what field this business', $captured);

        // No vertical is baked into the policy text itself.
        foreach (['education', 'clothing', 'programming course', 'fashion'] as $vertical) {
            $this->assertStringNotContainsString($vertical, $captured);
        }
    }

    /**
     * A weak retrieval result is handed over labelled rather than withheld: the
     * model can disregard a poor chunk, but it cannot use one it never saw.
     */
    public function test_weakly_matched_chunks_reach_the_prompt_marked_as_unconfirmed(): void
    {
        $agent = Agent::factory()->create();
        $source = KnowledgeSource::factory()->for($agent)->create(['name' => 'учителя']);

        $chunk = new KnowledgeChunk([
            'content' => 'Воситов Равшан - веб разработка',
            'embedding' => [0.05, 0.9987, 0],
            'embedding_model' => 'test-embedding-model',
            'chunk_index' => 0,
        ]);
        $chunk->agent_id = $agent->id;
        $chunk->knowledge_source_id = $source->id;
        $chunk->save();

        $captured = $this->capturePrompt($agent, 'расскажите про учителей', 'test-embedding-model');

        $this->assertStringContainsString('POSSIBLY RELATED', $captured);
        $this->assertStringContainsString('Воситов Равшан', $captured);
    }

    /**
     * Capture the system prompt built for one message, without asserting
     * anything about the reply itself.
     */
    protected function capturePrompt(Agent $agent, string $message, string $embeddingModel = 'test-embedding-model'): string
    {
        $captured = '';

        $this->mock(LlmProviderInterface::class, function ($mock) use (&$captured, $embeddingModel) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('embeddingModel')->andReturn($embeddingModel);
            $mock->shouldReceive('generate')
                ->withArgs(function ($systemPrompt) use (&$captured) {
                    $captured = $systemPrompt;

                    return true;
                })
                ->andReturn(['text' => 'ok', 'input_tokens' => 10, 'output_tokens' => 5, 'finish_reason' => 'STOP']);
        });

        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $this->app->make(AgentReplyService::class)->reply($conversation, $message);

        return $captured;
    }

    public function test_conversation_history_is_included_in_subsequent_prompts(): void
    {
        $capturedContents = null;

        $this->mock(LlmProviderInterface::class, function ($mock) use (&$capturedContents) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')
                ->withArgs(function ($systemPrompt, $contents) use (&$capturedContents) {
                    $capturedContents = $contents;

                    return true;
                })
                ->andReturn(['text' => 'Sure, here is more info.', 'input_tokens' => 10, 'output_tokens' => 5, 'finish_reason' => 'STOP']);
        });

        $agent = Agent::factory()->create();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $service = $this->app->make(AgentReplyService::class);

        // First turn establishes history.
        $service->reply($conversation, 'Do you have red dresses?');
        // Second turn should see the first exchange as history.
        $service->reply($conversation, 'What sizes does it come in?');

        $this->assertNotNull($capturedContents);
        $this->assertCount(3, $capturedContents);
        $this->assertSame(['role' => 'user', 'text' => 'Do you have red dresses?'], $capturedContents[0]);
        $this->assertSame('model', $capturedContents[1]['role']);
        $this->assertSame('Sure, here is more info.', $capturedContents[1]['text']);
        $this->assertSame(['role' => 'user', 'text' => 'What sizes does it come in?'], $capturedContents[2]);
    }

    public function test_system_prompt_enforces_messenger_brevity_rules(): void
    {
        $captured = null;

        $this->mock(LlmProviderInterface::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')
                ->withArgs(function ($systemPrompt) use (&$captured) {
                    $captured = $systemPrompt;

                    return true;
                })
                ->andReturn(['text' => 'Да, есть.', 'input_tokens' => 5, 'output_tokens' => 3, 'finish_reason' => 'STOP']);
        });

        $agent = Agent::factory()->create();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $this->app->make(AgentReplyService::class)->reply($conversation, 'Есть красное платье?');

        $this->assertStringContainsString('2-4 sentences', $captured);
        $this->assertStringContainsString('not an email', $captured);
        $this->assertStringContainsString('bullet points', $captured);
        $this->assertStringContainsString('at most ONE product', $captured);
        $this->assertStringContainsString('at most ONE question', $captured);
    }

    public function test_proactivity_level_shapes_the_system_prompt(): void
    {
        foreach (['off' => 'Proactivity: OFF', 'subtle' => 'Proactivity: SUBTLE', 'active' => 'Proactivity: ACTIVE'] as $level => $marker) {
            $captured = null;

            $this->mock(LlmProviderInterface::class, function ($mock) use (&$captured) {
                $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
                $mock->shouldReceive('generate')
                    ->withArgs(function ($systemPrompt) use (&$captured) {
                        $captured = $systemPrompt;

                        return true;
                    })
                    ->andReturn(['text' => 'ok', 'input_tokens' => 1, 'output_tokens' => 1, 'finish_reason' => 'stop']);
            });

            $agent = Agent::factory()->create(['proactivity' => $level]);
            $conversation = $agent->conversations()->create(['status' => 'ai']);
            $this->app->make(AgentReplyService::class)->reply($conversation, 'Есть красное платье?');

            $this->assertStringContainsString($marker, $captured, "missing marker for proactivity={$level}");
            // Every level keeps the catalog-only guardrail.
            $this->assertStringContainsString('KNOWLEDGE BASE', $captured);
        }
    }

    public function test_proactivity_off_forbids_upsell_while_active_encourages_a_next_step(): void
    {
        $prompts = [];

        foreach (['off', 'active'] as $level) {
            $this->mock(LlmProviderInterface::class, function ($mock) use (&$prompts) {
                $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
                $mock->shouldReceive('generate')
                    ->withArgs(function ($systemPrompt) use (&$prompts) {
                        $prompts[] = $systemPrompt;

                        return true;
                    })
                    ->andReturn(['text' => 'ok', 'input_tokens' => 1, 'output_tokens' => 1, 'finish_reason' => 'stop']);
            });

            $agent = Agent::factory()->create(['proactivity' => $level]);
            $this->app->make(AgentReplyService::class)->reply($agent->conversations()->create(['status' => 'ai']), 'Привет');
        }

        $this->assertStringContainsString('Do not upsell', $prompts[0]);
        $this->assertStringContainsString('move the sale forward', $prompts[1]);
    }

    public function test_custom_system_prompt_is_injected_with_priority_before_default_tone(): void
    {
        $captured = null;

        $this->mock(LlmProviderInterface::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')
                ->withArgs(function ($systemPrompt) use (&$captured) {
                    $captured = $systemPrompt;

                    return true;
                })
                ->andReturn(['text' => 'OK', 'input_tokens' => 5, 'output_tokens' => 3, 'finish_reason' => 'STOP']);
        });

        $agent = Agent::factory()->create([
            'tone' => 'formal',
            'custom_system_prompt' => 'Always address the customer as "друг" and keep every reply to one word.',
        ]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $this->app->make(AgentReplyService::class)->reply($conversation, 'Привет');

        $this->assertStringContainsString('Always address the customer as "друг"', $captured);
        $this->assertStringContainsString('take priority over the default tone/formatting guidance', $captured);

        // The owner's instruction must appear BEFORE the default tone line, so a model
        // reading top-to-bottom sees the override before the thing it's overriding.
        $ownerPos = strpos($captured, 'Always address the customer as');
        $defaultTonePos = strpos($captured, 'Default tone');
        $this->assertNotFalse($ownerPos);
        $this->assertNotFalse($defaultTonePos);
        $this->assertLessThan($defaultTonePos, $ownerPos);
    }

    public function test_language_instruction_asks_for_consistency_once_a_reply_exists(): void
    {
        $captured = null;

        $this->mock(LlmProviderInterface::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')
                ->withArgs(function ($systemPrompt) use (&$captured) {
                    $captured = $systemPrompt;

                    return true;
                })
                ->andReturn(['text' => 'ok', 'input_tokens' => 1, 'output_tokens' => 1, 'finish_reason' => 'STOP']);
        });

        $agent = Agent::factory()->create(['language' => 'auto']);
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $service = $this->app->make(AgentReplyService::class);

        $service->reply($conversation, 'Salom');
        $this->assertStringContainsString('default to Russian', $captured);

        $service->reply($conversation, '?');
        $this->assertStringContainsString('keep using whatever language your own earlier replies', $captured);
    }

    public function test_ask_for_product_name_skips_retrieval_and_stays_short(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldNotReceive('embed');
            $mock->shouldReceive('generate')
                ->withArgs(function ($systemPrompt, $contents, $options) {
                    return str_contains($systemPrompt, 'asking them to tell you the product name')
                        && ($options['max_output_tokens'] ?? null) === 128;
                })
                ->andReturn(['text' => 'Уточните, пожалуйста, название товара?', 'input_tokens' => 15, 'output_tokens' => 6, 'finish_reason' => 'STOP']);
        });

        $agent = Agent::factory()->create();
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $userMsg = $conversation->messages()->create(['role' => 'user', 'content' => '[Customer sent an image]', 'external_message_id' => 'x1']);

        $service = $this->app->make(AgentReplyService::class);
        $assistant = $service->askForProductName($conversation, $userMsg);

        $this->assertSame('Уточните, пожалуйста, название товара?', $assistant->content);
        $this->assertTrue($assistant->meta['unclear_photo']);
        $this->assertSame([], $assistant->meta['retrieved_chunks']);
    }

    // --- Graceful degradation: greeting shortcut + fallback suppression ---

    public function test_simple_greeting_gets_greeting_message_when_gemini_fails(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->andThrow(new LlmException('quota exceeded'));
        });

        $agent = Agent::factory()->create([
            'response_format' => ['greeting_message' => 'Здравствуйте! Рады видеть вас снова.'],
        ]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $service = $this->app->make(AgentReplyService::class);

        foreach (['Привет', 'привет!', 'Здравствуйте', 'Hello', 'Salom'] as $greeting) {
            $result = $service->reply($conversation, $greeting);
            $this->assertSame('Здравствуйте! Рады видеть вас снова.', $result['assistant']->content, "failed for greeting: {$greeting}");
            $this->assertTrue($result['assistant']->meta['failed']);
        }
    }

    public function test_non_greeting_still_gets_generic_fallback_when_gemini_fails(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->andThrow(new LlmException('quota exceeded'));
        });

        $agent = Agent::factory()->create([
            'response_format' => [
                'greeting_message' => 'Здравствуйте! Рады видеть вас снова.',
                'fallback_message' => 'Уточню и вернусь с ответом.',
            ],
        ]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $service = $this->app->make(AgentReplyService::class);

        $result = $service->reply($conversation, 'Сколько стоит красное платье?');

        $this->assertSame('Уточню и вернусь с ответом.', $result['assistant']->content);
    }

    public function test_should_suppress_reply_is_false_with_no_prior_messages(): void
    {
        $agent = Agent::factory()->create();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $this->assertFalse($this->app->make(AgentReplyService::class)->shouldSuppressReply($conversation));
    }

    public function test_should_suppress_reply_is_true_right_after_a_failed_reply(): void
    {
        $agent = Agent::factory()->create();
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Извините...',
            'meta' => ['failed' => true],
        ]);

        $this->assertTrue($this->app->make(AgentReplyService::class)->shouldSuppressReply($conversation));
    }

    public function test_should_suppress_reply_is_false_after_a_successful_reply(): void
    {
        $agent = Agent::factory()->create();
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Да, есть в наличии.',
            'meta' => ['failed' => false],
        ]);

        $this->assertFalse($this->app->make(AgentReplyService::class)->shouldSuppressReply($conversation));
    }

    public function test_should_suppress_reply_is_false_once_the_window_has_passed(): void
    {
        $agent = Agent::factory()->create();
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $failedReply = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Извините...',
            'meta' => ['failed' => true],
        ]);
        $failedReply->forceFill(['created_at' => now()->subMinutes(10)])->save();

        $this->assertFalse($this->app->make(AgentReplyService::class)->shouldSuppressReply($conversation));
    }
}
