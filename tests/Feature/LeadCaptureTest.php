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

    // --- asking for a phone number, without ever blocking the order ----------

    /**
     * The order is not filed on the spot when nobody has given a number: the
     * customer is asked once, first. Nothing is saved on this turn — the model
     * wanting to record an order is not the same as the order being complete.
     */
    public function test_the_first_order_attempt_without_a_number_asks_for_one_instead(): void
    {
        Queue::fake();

        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->andReturn(
                $this->toolCallResult(['items' => 'OMEN 16']),
                $this->textResult('Записываю OMEN 16. Чтобы мы могли с вами связаться, оставьте, пожалуйста, номер телефона.'),
            );
        });

        $agent = Agent::factory()->create(['capture_leads' => true]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $assistant = $this->app->make(AgentReplyService::class)
            ->reply($conversation, 'Беру OMEN 16, оформляйте')['assistant'];

        $this->assertStringContainsString('номер телефона', $assistant->content);
        $this->assertDatabaseCount('leads', 0);
        $this->assertFalse($assistant->meta['lead_captured']);
        // Recorded on the turn that asked, so the next attempt does not ask again.
        $this->assertTrue($assistant->meta[LeadCaptureService::ASKED_FOR_PHONE]);

        Queue::assertNotPushed(SendLeadNotificationJob::class);
    }

    /**
     * Requirement 2: they gave a number, so the order carries it and the
     * customer is told they will be called.
     */
    public function test_an_order_with_a_phone_number_promises_a_callback(): void
    {
        Queue::fake();

        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->andReturn(
                $this->toolCallResult(['customer_name' => 'Иван', 'phone' => '+992 90 123 45 67', 'items' => 'OMEN 16']),
                $this->textResult('Спасибо! Мы вам позвоним для подтверждения заказа.'),
            );
        });

        $agent = Agent::factory()->create(['capture_leads' => true]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $assistant = $this->app->make(AgentReplyService::class)
            ->reply($conversation, 'Беру OMEN 16, мой номер +992 90 123 45 67')['assistant'];

        $lead = $agent->leads()->sole();

        // Stored dialable, not as the customer happened to type it.
        $this->assertSame('+992901234567', $lead->phone);
        $this->assertFalse($lead->isChatOnly());
        $this->assertStringContainsString('позвоним', $assistant->content);
        $this->assertTrue($assistant->meta['lead_captured']);

        Queue::assertPushed(SendLeadNotificationJob::class);
    }

    /**
     * Requirement 3, and the one that matters most: the customer says no, and
     * the order is created anyway — with no number, and with a confirmation
     * that does not promise a call nobody can make.
     */
    public function test_an_order_is_still_created_when_the_customer_declines_to_give_a_number(): void
    {
        Queue::fake();

        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->andReturn(
                // Turn 1: the model wants to record the order, and is asked to
                // ask for a number instead.
                $this->toolCallResult(['items' => 'OMEN 16']),
                $this->textResult('Чтобы мы могли с вами связаться, оставьте, пожалуйста, номер телефона.'),
                // Turn 2: the customer refuses, the model records it anyway.
                $this->toolCallResult(['items' => 'OMEN 16', 'note' => 'Номер давать отказался']),
                $this->textResult('Хорошо, заявка принята! Мы свяжемся с вами здесь, в чате.'),
            );
        });

        $agent = Agent::factory()->create(['capture_leads' => true]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $service = $this->app->make(AgentReplyService::class);

        $service->reply($conversation, 'Беру OMEN 16, оформляйте');
        $this->assertDatabaseCount('leads', 0);

        $assistant = $service->reply($conversation, 'Номер не дам, пишите сюда')['assistant'];

        $lead = $agent->leads()->sole();

        $this->assertNull($lead->phone);
        $this->assertTrue($lead->isChatOnly());
        $this->assertSame('OMEN 16', $lead->items);
        $this->assertStringContainsString('в чате', $assistant->content);
        $this->assertStringNotContainsString('позвоним', $assistant->content);
        $this->assertTrue($assistant->meta['lead_captured']);

        // The order still reaches the owner — that is the whole point of not
        // blocking it.
        Queue::assertPushed(SendLeadNotificationJob::class);
    }

    /**
     * A number given earlier in the conversation is the customer's number, even
     * when the model does not repeat it in the tool call — it has already "seen"
     * it, and the order would otherwise be filed as uncallable.
     */
    public function test_a_number_given_earlier_in_the_conversation_is_used(): void
    {
        Queue::fake();

        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->andReturn(
                $this->textResult('Записал ваш номер.'),
                $this->toolCallResult(['items' => 'OMEN 16']),
                $this->textResult('Спасибо! Мы вам позвоним для подтверждения заказа.'),
            );
        });

        $agent = Agent::factory()->create(['capture_leads' => true]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $service = $this->app->make(AgentReplyService::class);

        $service->reply($conversation, 'Мой номер 901234567, посмотрю ноутбуки');
        $assistant = $service->reply($conversation, 'Беру OMEN 16')['assistant'];

        // Recorded straight away: there was no reason to ask.
        $this->assertSame('901234567', $agent->leads()->sole()->phone);
        $this->assertFalse($assistant->meta[LeadCaptureService::ASKED_FOR_PHONE]);
    }

    /**
     * Asking twice is nagging. Once the question has been put, every later
     * attempt records the order whatever the customer said.
     */
    public function test_the_customer_is_only_ever_asked_once(): void
    {
        Queue::fake();

        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->andReturn(
                $this->toolCallResult(['items' => 'OMEN 16']),
                $this->textResult('Оставьте, пожалуйста, номер телефона.'),
                $this->toolCallResult(['items' => 'OMEN 16']),
                $this->textResult('Хорошо, заявка принята! Мы свяжемся с вами здесь, в чате.'),
                $this->toolCallResult(['items' => 'Macbook Air 15']),
                $this->textResult('Хорошо, вторая заявка принята, ответим здесь.'),
            );
        });

        $agent = Agent::factory()->create(['capture_leads' => true]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);
        $service = $this->app->make(AgentReplyService::class);

        $service->reply($conversation, 'Беру OMEN 16');
        $service->reply($conversation, 'Не хочу оставлять номер');
        $service->reply($conversation, 'И ещё Macbook Air 15 добавьте');

        // Two orders, one question.
        $this->assertSame(2, $agent->leads()->count());
        $this->assertSame(0, $agent->leads()->whereNotNull('phone')->count());
    }

    /**
     * The prompt half of the rule, so the model asks of its own accord in the
     * cases the pipeline does not intercept.
     */
    public function test_the_prompt_tells_the_model_to_ask_before_recording(): void
    {
        $captured = null;

        $this->mock(LlmProviderInterface::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')
                ->withArgs(function ($prompt) use (&$captured) {
                    $captured = $prompt;

                    return true;
                })
                ->andReturn($this->textResult('Конечно!'));
        });

        $agent = Agent::factory()->create(['capture_leads' => true]);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $this->app->make(AgentReplyService::class)->reply($conversation, 'Здравствуйте');

        $this->assertStringContainsString('ASK FOR A PHONE NUMBER FIRST', $captured);
        $this->assertStringContainsString('RECORD THE ORDER ANYWAY', $captured);
        $this->assertStringContainsString('Never say you will call someone whose number you do not have', $captured);
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
                    return $this->toolCallResult(['phone' => '+992901112233', 'items' => 'Куртка']);
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
            ->reply($conversation, 'Запишите куртку, мой номер +992 901 11 22 33')['assistant'];

        // The lead survives; the customer gets the templated confirmation.
        $this->assertDatabaseHas('leads', ['agent_id' => $agent->id, 'items' => 'Куртка', 'phone' => '+992901112233']);
        $this->assertSame('Заявка принята!', $assistant->content);
        $this->assertTrue($assistant->meta['lead_captured']);
    }
}
