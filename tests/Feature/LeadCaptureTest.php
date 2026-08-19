<?php

namespace Tests\Feature;

use App\Exceptions\LlmException;
use App\Jobs\SendLeadNotificationJob;
use App\Models\Agent;
use App\Services\AgentReplyService;
use App\Services\LeadCaptureService;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LeadCaptureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A generate() result carrying a create_lead tool call.
     */
    protected function toolCallResult(array $arguments): array
    {
        return [
            'text' => '',
            'input_tokens' => 10,
            'output_tokens' => 5,
            'finish_reason' => 'tool_calls',
            'tool_calls' => [[
                'id' => 'call_1',
                'name' => LeadCaptureService::TOOL_NAME,
                'arguments' => $arguments,
            ]],
        ];
    }

    protected function textResult(string $text): array
    {
        return ['text' => $text, 'input_tokens' => 8, 'output_tokens' => 4, 'finish_reason' => 'stop', 'tool_calls' => []];
    }

    public function test_a_create_lead_tool_call_persists_a_lead_and_confirms_to_the_customer(): void
    {
        Queue::fake();

        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->andReturn(
                $this->toolCallResult([
                    'customer_name' => 'Иван',
                    'contact' => '+992900112233',
                    'items' => '2 красных платья, размер M',
                    'note' => 'Доставка завтра',
                ]),
                $this->textResult('Спасибо, Иван! Ваш заказ принят, скоро свяжемся.'),
            );
        });

        $agent = Agent::factory()->create(['capture_leads' => true]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $assistant = $this->app->make(AgentReplyService::class)
            ->reply($conversation, 'Хочу заказать 2 красных платья размер M')['assistant'];

        $this->assertSame('Спасибо, Иван! Ваш заказ принят, скоро свяжемся.', $assistant->content);
        $this->assertTrue($assistant->meta['lead_captured']);

        $this->assertDatabaseHas('leads', [
            'agent_id' => $agent->id,
            'conversation_id' => $conversation->id,
            'customer_name' => 'Иван',
            'contact' => '+992900112233',
            'items' => '2 красных платья, размер M',
            'note' => 'Доставка завтра',
            'source' => 'playground',
            'status' => 'new',
        ]);

        $lead = $agent->leads()->first();
        Queue::assertPushed(SendLeadNotificationJob::class, fn ($job) => $job->lead->id === $lead->id);
    }

    public function test_no_lead_is_created_for_an_ordinary_reply(): void
    {
        Queue::fake();

        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->once()->andReturn($this->textResult('Да, есть в наличии.'));
        });

        $agent = Agent::factory()->create(['capture_leads' => true]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $assistant = $this->app->make(AgentReplyService::class)
            ->reply($conversation, 'Есть красное платье?')['assistant'];

        $this->assertSame('Да, есть в наличии.', $assistant->content);
        $this->assertFalse($assistant->meta['lead_captured']);
        $this->assertDatabaseCount('leads', 0);
        Queue::assertNotPushed(SendLeadNotificationJob::class);
    }

    public function test_the_create_lead_tool_is_only_offered_when_capture_is_enabled(): void
    {
        $capturedOptions = [];

        $this->mock(LlmProviderInterface::class, function ($mock) use (&$capturedOptions) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')
                ->andReturnUsing(function ($system, $contents, $options) use (&$capturedOptions) {
                    $capturedOptions[] = $options;

                    return $this->textResult('Ок.');
                });
        });

        $service = $this->app->make(AgentReplyService::class);

        // Asserted on the tool NAMES rather than on the presence of the `tools`
        // key: escalation detection offers a tool of its own on the same call,
        // so "no tools at all" is no longer what "lead capture off" looks like.
        $toolNames = fn (array $options) => array_column($options['tools'] ?? [], 'name');

        $enabled = Agent::factory()->create(['capture_leads' => true]);
        $service->reply($enabled->conversations()->create(['status' => 'ai']), 'Привет');
        $this->assertContains(LeadCaptureService::TOOL_NAME, $toolNames($capturedOptions[0]));

        $capturedOptions = [];
        $disabled = Agent::factory()->create(['capture_leads' => false]);
        $service->reply($disabled->conversations()->create(['status' => 'ai']), 'Привет');
        $this->assertNotContains(LeadCaptureService::TOOL_NAME, $toolNames($capturedOptions[0]));
    }

    public function test_no_tools_are_offered_when_both_capture_and_escalation_are_off(): void
    {
        $capturedOptions = [];

        $this->mock(LlmProviderInterface::class, function ($mock) use (&$capturedOptions) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')
                ->andReturnUsing(function ($system, $contents, $options) use (&$capturedOptions) {
                    $capturedOptions[] = $options;

                    return $this->textResult('Ок.');
                });
        });

        $agent = Agent::factory()->create([
            'capture_leads' => false,
            'behavior' => ['on_not_found' => 'apologize', 'escalation' => 'off'],
        ]);

        $this->app->make(AgentReplyService::class)
            ->reply($agent->conversations()->create(['status' => 'ai']), 'Привет');

        $this->assertArrayNotHasKey('tools', $capturedOptions[0]);
    }

    public function test_lead_is_saved_even_when_the_confirmation_generation_fails(): void
    {
        Queue::fake();

        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            // First call returns the tool call; the follow-up confirmation call fails.
            $mock->shouldReceive('generate')->andReturnUsing(function () {
                static $calls = 0;
                $calls++;
                if ($calls === 1) {
                    return $this->toolCallResult(['contact' => '+992001', 'items' => 'Куртка']);
                }
                throw new LlmException('quota exceeded');
            });
        });

        $agent = Agent::factory()->create([
            'capture_leads' => true,
            'response_format' => ['lead_confirmation' => 'Заявка принята!'],
        ]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $assistant = $this->app->make(AgentReplyService::class)
            ->reply($conversation, 'Запишите куртку, мой номер +992001')['assistant'];

        // The lead survives; the customer gets the templated confirmation.
        $this->assertDatabaseHas('leads', ['agent_id' => $agent->id, 'items' => 'Куртка', 'contact' => '+992001']);
        $this->assertSame('Заявка принята!', $assistant->content);
        $this->assertTrue($assistant->meta['lead_captured']);
    }
}
