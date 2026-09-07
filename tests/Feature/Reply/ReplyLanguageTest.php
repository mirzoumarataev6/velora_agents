<?php

namespace Tests\Feature\Reply;

use App\Models\Agent;
use App\Services\AgentReplyService;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The language the assistant is told to write in, across a conversation that
 * changes language mid-thread.
 */
class ReplyLanguageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Capture every system prompt the pipeline builds, in order.
     *
     * @return array<int, string>
     */
    protected function promptsFor(Agent $agent, array $customerMessages): array
    {
        $prompts = [];

        $this->mock(LlmProviderInterface::class, function ($mock) use (&$prompts) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');
            $mock->shouldReceive('generate')->andReturnUsing(
                function (string $prompt) use (&$prompts) {
                    $prompts[] = $prompt;

                    return [
                        'text' => 'Хуб.', 'input_tokens' => 10, 'output_tokens' => 5,
                        'finish_reason' => 'STOP', 'tool_calls' => [],
                    ];
                }
            );
        });

        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $service = $this->app->make(AgentReplyService::class);

        foreach ($customerMessages as $message) {
            $service->reply($conversation, $message);
        }

        return $prompts;
    }

    /**
     * The reported thread: a customer opens in Russian, switches to Tajik, and
     * keeps getting Russian back.
     */
    public function test_the_reply_language_follows_the_customer_mid_conversation(): void
    {
        $agent = Agent::factory()->create();

        $prompts = $this->promptsFor($agent, [
            'Здравствуйте, сколько стоит доставка?',
            'Дурустар кобед',
            '20 бор якта диск шара т',
        ]);

        $this->assertStringContainsString('The customer is writing in Russian. WRITE YOUR REPLY IN Russian.', $prompts[0]);

        foreach ([1, 2] as $turn) {
            $this->assertStringContainsString(
                'The customer is writing in Tajik. WRITE YOUR REPLY IN Tajik.',
                $prompts[$turn],
                "turn {$turn} should have switched to Tajik",
            );
            // The two languages share an alphabet, so "reply in Tajik" alone is
            // satisfiable by Russian that looks close enough.
            $this->assertStringContainsString('this customer is writing TAJIK, not Russian', $prompts[$turn]);
        }
    }

    public function test_the_language_switches_back_when_the_customer_does(): void
    {
        $agent = Agent::factory()->create();

        $prompts = $this->promptsFor($agent, ['Салом! Чӣ хел шумо?', 'А сколько стоит доставка в Худжанд?']);

        $this->assertStringContainsString('WRITE YOUR REPLY IN Tajik', $prompts[0]);
        $this->assertStringContainsString('WRITE YOUR REPLY IN Russian', $prompts[1]);
    }

    /**
     * A message with no language of its own must not reset the conversation.
     */
    public function test_a_message_with_no_language_signal_keeps_the_current_language(): void
    {
        $agent = Agent::factory()->create();

        $prompts = $this->promptsFor($agent, ['Мехохам ноутбук харидан', 'ок']);

        $this->assertStringContainsString('WRITE YOUR REPLY IN Tajik', $prompts[1]);
    }

    public function test_english_is_detected_too(): void
    {
        $agent = Agent::factory()->create();

        $prompts = $this->promptsFor($agent, ['Hello, do you have gaming laptops under 15000?']);

        $this->assertStringContainsString('WRITE YOUR REPLY IN English', $prompts[0]);
    }

    /**
     * There is no longer such a thing as an agent pinned to one language: the
     * selector is gone from the form, and a stale value left in the column by
     * an owner who set one before must not resurrect the old behaviour.
     * Detection runs for everyone, on every message.
     */
    public function test_a_stale_pinned_language_no_longer_overrides_detection(): void
    {
        $agent = Agent::factory()->create();
        // Written past the model: `language` is not fillable any more, which is
        // exactly the state an upgraded account is in — an old value in the
        // column that nothing may read.
        $agent->forceFill(['language' => 'ru'])->save();

        $prompts = $this->promptsFor($agent->refresh(), ['Дурустар кобед']);

        $this->assertStringContainsString('WRITE YOUR REPLY IN Tajik', $prompts[0]);
        $this->assertStringNotContainsString('Always reply in Russian', $prompts[0]);
    }
}
