<?php

namespace Tests\Feature;

use App\Jobs\SendEscalationNotificationJob;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\User;
use App\Services\AgentReplyService;
use App\Services\Channels\ChannelDriverManager;
use App\Services\EscalationService;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Automatic "needs human" detection.
 *
 * The signal is a tool call the model makes on the SAME generation that writes
 * the reply, so these drive the pipeline through a mocked provider and assert on
 * what the pipeline does with the call — including, importantly, what it does
 * NOT do when the call is weak or absent.
 */
class EscalationDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected function agent(array $behavior = [], ?User $owner = null): Agent
    {
        return Agent::factory()->for($owner ?? User::factory()->create())->create([
            'behavior' => $behavior + ['on_not_found' => 'apologize', 'escalation' => 'conservative'],
            'response_format' => [],
        ]);
    }

    /**
     * Mock a generation that returns $text and optionally an escalation call.
     */
    protected function mockLlm(string $text, ?array $escalationArgs = null, ?string &$systemPrompt = null): void
    {
        $toolCalls = $escalationArgs === null ? [] : [[
            'id' => 'call_1',
            'name' => EscalationService::TOOL_NAME,
            'arguments' => $escalationArgs,
        ]];

        $this->mock(LlmProviderInterface::class, function ($mock) use ($text, $toolCalls, &$systemPrompt) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')
                ->withArgs(function ($prompt, $contents, $options = []) use (&$systemPrompt) {
                    $systemPrompt = $prompt;

                    return true;
                })
                ->andReturn([
                    'text' => $text,
                    'tool_calls' => $toolCalls,
                    'input_tokens' => 10,
                    'output_tokens' => 5,
                ]);
        });
    }

    // ---------------------------------------------------------------
    // Flagging
    // ---------------------------------------------------------------

    public function test_an_angry_russian_message_flags_the_conversation(): void
    {
        Queue::fake();

        $agent = $this->agent();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $this->mockLlm('Извините за задержку! Я передам вас коллеге.', [
            'signal' => 'frustration',
            'confidence' => 0.93,
            'summary' => 'Заказ не пришёл, клиент злится',
        ]);

        $this->app->make(AgentReplyService::class)
            ->reply($conversation, 'Это возмутительно! Третий раз пишу, заказ так и не пришёл. Верните деньги!');

        $conversation->refresh();

        $this->assertNotNull($conversation->needs_human_at);
        $this->assertSame('frustration', $conversation->needs_human_signal);
        $this->assertSame('Заказ не пришёл, клиент злится', $conversation->needs_human_summary);
        // Which is what puts it under the «Нужен человек» filter.
        $this->assertSame('human_takeover', $conversation->status);

        Queue::assertPushed(SendEscalationNotificationJob::class);
    }

    public function test_an_angry_tajik_message_flags_the_conversation(): void
    {
        Queue::fake();

        $agent = $this->agent();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $this->mockLlm('Бубахшед! Ҳамкорам ба шумо кӯмак мекунад.', [
            'signal' => 'complaint',
            'confidence' => 0.9,
            'summary' => 'Мол вайрон аст, муштарӣ норозӣ',
        ]);

        $this->app->make(AgentReplyService::class)
            ->reply($conversation, 'Ин чӣ хел хизматрасонӣ аст?! Мол вайрон омад, пуламро баргардонед!');

        $conversation->refresh();

        $this->assertNotNull($conversation->needs_human_at);
        $this->assertSame('complaint', $conversation->needs_human_signal);
        $this->assertSame('human_takeover', $conversation->status);

        Queue::assertPushed(SendEscalationNotificationJob::class);
    }

    /**
     * The whole design rests on this: an ordinary question must never flag.
     */
    public function test_a_normal_question_does_not_flag_anything(): void
    {
        Queue::fake();

        $agent = $this->agent();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        // No tool call at all — the model saw nothing to report.
        $this->mockLlm('Доставка по городу занимает 1-2 дня.');

        $this->app->make(AgentReplyService::class)
            ->reply($conversation, 'Здравствуйте! Подскажите, сколько идёт доставка?');

        $conversation->refresh();

        $this->assertNull($conversation->needs_human_at);
        $this->assertSame('ai', $conversation->status);

        Queue::assertNotPushed(SendEscalationNotificationJob::class);
    }

    public function test_a_mildly_confused_customer_does_not_flag(): void
    {
        Queue::fake();

        $agent = $this->agent();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $this->mockLlm('Поясню: размер L соответствует 48-50.');

        $this->app->make(AgentReplyService::class)
            ->reply($conversation, 'Не понял, а это какой размер?');

        $this->assertNull($conversation->refresh()->needs_human_at);
        Queue::assertNotPushed(SendEscalationNotificationJob::class);
    }

    // ---------------------------------------------------------------
    // The confidence bar
    // ---------------------------------------------------------------

    /**
     * A low-confidence guess is dropped: below the bar it is not a signal.
     */
    public function test_a_low_confidence_signal_is_ignored(): void
    {
        Queue::fake();

        $agent = $this->agent(['escalation' => 'conservative']);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $this->mockLlm('Понимаю вас.', ['signal' => 'frustration', 'confidence' => 0.6]);

        $this->app->make(AgentReplyService::class)->reply($conversation, 'Ну сколько можно ждать');

        $conversation->refresh();
        $this->assertNull($conversation->needs_human_at);
        $this->assertSame('ai', $conversation->status);
        Queue::assertNotPushed(SendEscalationNotificationJob::class);
    }

    public function test_the_same_signal_flags_once_sensitivity_is_raised(): void
    {
        Queue::fake();

        $agent = $this->agent(['escalation' => 'sensitive']);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $this->mockLlm('Понимаю вас.', ['signal' => 'frustration', 'confidence' => 0.6]);

        $this->app->make(AgentReplyService::class)->reply($conversation, 'Ну сколько можно ждать');

        $this->assertNotNull($conversation->refresh()->needs_human_at);
        Queue::assertPushed(SendEscalationNotificationJob::class);
    }

    public function test_detection_can_be_switched_off_per_agent(): void
    {
        Queue::fake();

        $agent = $this->agent(['escalation' => 'off']);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $this->mockLlm('Извините!', ['signal' => 'frustration', 'confidence' => 0.99]);

        $this->app->make(AgentReplyService::class)->reply($conversation, 'Отвратительный сервис!');

        $conversation->refresh();
        $this->assertNull($conversation->needs_human_at);
        $this->assertSame('ai', $conversation->status);
        Queue::assertNotPushed(SendEscalationNotificationJob::class);
    }

    public function test_the_tool_is_not_offered_when_detection_is_off(): void
    {
        $agent = $this->agent(['escalation' => 'off']);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $prompt = null;
        $this->mockLlm('Здравствуйте!', null, $prompt);

        $this->app->make(AgentReplyService::class)->reply($conversation, 'Привет');

        $this->assertStringNotContainsString(EscalationService::TOOL_NAME, (string) $prompt);
    }

    public function test_the_guidance_is_in_the_prompt_when_detection_is_on(): void
    {
        $agent = $this->agent(['escalation' => 'conservative']);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $prompt = null;
        $this->mockLlm('Здравствуйте!', null, $prompt);

        $this->app->make(AgentReplyService::class)->reply($conversation, 'Привет');

        $this->assertStringContainsString(EscalationService::TOOL_NAME, (string) $prompt);
        // And the instruction that stops flagging from becoming silence.
        $this->assertStringContainsString('still answer the customer in the same turn', (string) $prompt);
    }

    /**
     * An explicit "give me a human" is an observation rather than an inference,
     * so it clears a lower bar than a mood read from tone.
     */
    public function test_an_explicit_request_for_a_human_clears_a_lower_bar(): void
    {
        Queue::fake();

        $agent = $this->agent(['escalation' => 'conservative']);
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        // 0.6 is below the conservative frustration bar of 0.85.
        $this->mockLlm('Конечно, сейчас позову коллегу.', ['signal' => 'explicit_request', 'confidence' => 0.6]);

        $this->app->make(AgentReplyService::class)->reply($conversation, 'Позовите оператора, пожалуйста');

        $this->assertNotNull($conversation->refresh()->needs_human_at);
        $this->assertSame('explicit_request', $conversation->needs_human_signal);
    }

    // ---------------------------------------------------------------
    // The customer still gets an answer
    // ---------------------------------------------------------------

    public function test_the_agent_still_replies_when_it_flags(): void
    {
        Queue::fake();

        $agent = $this->agent();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $this->mockLlm('Мне очень жаль! Уже разбираюсь, коллега свяжется с вами.', [
            'signal' => 'frustration', 'confidence' => 0.95,
        ]);

        $result = $this->app->make(AgentReplyService::class)
            ->reply($conversation, 'Ужасное обслуживание!');

        $this->assertSame('Мне очень жаль! Уже разбираюсь, коллега свяжется с вами.', $result['assistant']->content);
        $this->assertTrue($result['assistant']->meta['escalated']);
        $this->assertSame('frustration', $result['assistant']->meta['escalation_signal']);
    }

    /**
     * Flagging must never turn into silence, even if the model spends its turn
     * on the tool call and writes nothing.
     */
    public function test_a_textless_flag_still_sends_an_acknowledgement(): void
    {
        Queue::fake();

        $agent = $this->agent();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $this->mockLlm('', ['signal' => 'frustration', 'confidence' => 0.95]);

        $result = $this->app->make(AgentReplyService::class)->reply($conversation, 'Безобразие!');

        $this->assertNotEmpty(trim($result['assistant']->content));
        // And it is an apology, not the generic "I don't know" fallback, which
        // would read as a brush-off to someone who has just complained.
        $this->assertStringContainsString('коллеге', $result['assistant']->content);
    }

    // ---------------------------------------------------------------
    // Notification behaviour
    // ---------------------------------------------------------------

    /**
     * The owner is told once, not on every angry message in a row.
     */
    public function test_a_second_angry_message_does_not_re_notify_within_the_window(): void
    {
        Queue::fake();

        $agent = $this->agent();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $escalation = $this->app->make(EscalationService::class);
        $args = ['signal' => 'frustration', 'confidence' => 0.95];

        $escalation->escalate($agent, $conversation, $args);
        $escalation->escalate($agent, $conversation->refresh(), $args);

        Queue::assertPushed(SendEscalationNotificationJob::class, 1);
    }

    public function test_the_window_re_arms_once_it_has_passed(): void
    {
        Queue::fake();

        $agent = $this->agent();
        $conversation = $agent->conversations()->create(['status' => 'ai']);

        $escalation = $this->app->make(EscalationService::class);
        $args = ['signal' => 'frustration', 'confidence' => 0.95];

        $escalation->escalate($agent, $conversation, $args);

        $conversation->forceFill([
            'needs_human_at' => now()->subMinutes((int) config('agent.escalation.renotify_after_minutes') + 10),
        ])->save();

        $escalation->escalate($agent, $conversation->refresh(), $args);

        Queue::assertPushed(SendEscalationNotificationJob::class, 2);
    }

    /**
     * An escalation must not silently reopen an archived conversation.
     */
    public function test_a_closed_conversation_stays_closed(): void
    {
        Queue::fake();

        $agent = $this->agent();
        $conversation = $agent->conversations()->create(['status' => 'closed']);

        $this->app->make(EscalationService::class)
            ->escalate($agent, $conversation, ['signal' => 'frustration', 'confidence' => 0.95]);

        $this->assertSame('closed', $conversation->refresh()->status);
        // Still flagged, so it is not lost — just not resurrected.
        $this->assertNotNull($conversation->needs_human_at);
    }

    public function test_the_owners_notification_mute_silences_escalations_too(): void
    {
        $owner = User::factory()->create(['lead_notifications_enabled' => false]);
        $agent = $this->agent([], $owner);
        $agent->forceFill([
            'notify_telegram_chat_id' => '555',
            'notify_telegram_linked_at' => now(),
        ])->save();
        Channel::factory()->for($agent)->create(['type' => 'telegram', 'status' => 'connected']);

        $conversation = $agent->conversations()->create([
            'status' => 'human_takeover',
            'needs_human_at' => now(),
            'needs_human_signal' => 'frustration',
        ]);

        $drivers = \Mockery::mock(ChannelDriverManager::class);
        $drivers->shouldNotReceive('for');

        (new SendEscalationNotificationJob($conversation))->handle($drivers);
    }
}
