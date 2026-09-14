<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\HandleIncomingMessageJob;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\InstagramDriver;
use App\Services\Channels\WhatsAppDriver;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The production-readiness gaps found in the WhatsApp and Instagram
 * integrations, each pinned by the test that would have caught it.
 *
 * All four were silent. None produced an error, a failed job or a log line an
 * owner would ever see — the integrations simply did less than they appeared
 * to, which is the hardest kind of bug to notice and the reason these are
 * tested rather than merely fixed.
 */
class MetaWebhookHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function mockLlm(string $reply = 'Здравствуйте! Чем помочь?'): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) use ($reply) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('embeddingModel')->andReturn('test-embedding-model');
            $mock->shouldReceive('generate')->andReturn([
                'text' => $reply, 'tool_calls' => [], 'input_tokens' => 10, 'output_tokens' => 5, 'finish_reason' => 'STOP',
            ]);
        });
    }

    protected function fakeMetaApi(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
            'graph.instagram.com/*' => Http::response(['message_id' => 'mid.OUT'], 200),
        ]);
    }

    /**
     * @param  array<int, array{string, string}>  $messages  [id, text] pairs, all from one customer.
     */
    protected function whatsAppBatch(array $messages, string $from = '15550001234'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => '1234567890'],
                        'contacts' => [['profile' => ['name' => 'Ivan'], 'wa_id' => $from]],
                        'messages' => array_map(fn ($m) => [
                            'from' => $from,
                            'id' => $m[0],
                            'timestamp' => (string) time(),
                            'type' => 'text',
                            'text' => ['body' => $m[1]],
                        ], $messages),
                    ],
                ]],
            ]],
        ];
    }

    // -----------------------------------------------------------------
    // 1. Webhook signatures — the check that was never implemented
    // -----------------------------------------------------------------

    /**
     * Both drivers used to return true from authenticateIncomingRequest()
     * unconditionally. The only thing between the internet and "inject any
     * message into any conversation" was the channel UUID in the URL — which
     * travels in server logs, proxy logs and Meta's dashboard, and is a URL,
     * not a secret.
     */
    public function test_an_unsigned_delivery_is_refused(): void
    {
        Queue::fake();
        $channel = Channel::factory()->whatsapp()->create();

        $this->postJson(route('webhooks.whatsapp', $channel), $this->whatsAppBatch([['wamid.1', 'Привет']]))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_delivery_signed_with_the_wrong_secret_is_refused(): void
    {
        Queue::fake();
        $channel = Channel::factory()->whatsapp()->create();

        $this->postSignedMetaWebhook(
            route('webhooks.whatsapp', $channel),
            $this->whatsAppBatch([['wamid.1', 'Привет']]),
            appSecret: 'not-the-right-secret-at-all',
        )->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_a_correctly_signed_delivery_is_accepted(): void
    {
        Queue::fake();
        $channel = Channel::factory()->whatsapp()->create();

        $this->postSignedMetaWebhook(route('webhooks.whatsapp', $channel), $this->whatsAppBatch([['wamid.1', 'Привет']]))
            ->assertOk();

        Queue::assertPushed(HandleIncomingMessageJob::class);
    }

    /** Instagram's webhook is the same public endpoint and gets the same lock. */
    public function test_instagram_refuses_an_unsigned_delivery_too(): void
    {
        Queue::fake();
        $channel = Channel::factory()->instagram()->create();

        $this->postJson(route('webhooks.instagram', $channel), [
            'object' => 'instagram',
            'entry' => [['id' => 'IG', 'messaging' => [[
                'sender' => ['id' => '778899'],
                'message' => ['mid' => 'mid.1', 'text' => 'Привет'],
            ]]]],
        ])->assertForbidden();

        Queue::assertNothingPushed();
    }

    /**
     * Fails closed. A channel connected before the app secret was collected
     * cannot have its deliveries verified, and accepting them unverified is
     * the exact hole this closes.
     */
    public function test_a_channel_with_no_stored_app_secret_refuses_everything(): void
    {
        Queue::fake();
        $channel = Channel::factory()->whatsapp()->create();
        $channel->forceFill(['credentials' => array_diff_key($channel->credentials, ['app_secret' => ''])])->save();

        $this->postSignedMetaWebhook(route('webhooks.whatsapp', $channel), $this->whatsAppBatch([['wamid.1', 'Привет']]))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    /** The GET handshake is unsigned by design — Meta sends it before any secret is agreed. */
    public function test_the_get_verification_handshake_still_works(): void
    {
        $channel = Channel::factory()->whatsapp()->create();

        $this->get(route('webhooks.whatsapp.verify', $channel)
            .'?hub.mode=subscribe&hub.verify_token='.$channel->credentials['verify_token'].'&hub.challenge=OK123')
            ->assertOk()
            ->assertSee('OK123');
    }

    // -----------------------------------------------------------------
    // 2. Batched deliveries — messages that were silently dropped
    // -----------------------------------------------------------------

    /**
     * Meta batches. `entry`, `changes` and `messages` are all arrays, and
     * messages arriving close together are posted together. The drivers read
     * index [0] of each, so the customer's second question was answered by
     * nobody and recorded nowhere.
     */
    public function test_every_message_in_a_batched_whatsapp_delivery_is_processed(): void
    {
        Queue::fake();
        $channel = Channel::factory()->whatsapp()->create();

        $this->postSignedMetaWebhook(route('webhooks.whatsapp', $channel), $this->whatsAppBatch([
            ['wamid.1', 'Есть размер M?'],
            ['wamid.2', 'И сколько стоит?'],
            ['wamid.3', 'Доставка есть?'],
        ]))->assertOk();

        Queue::assertPushed(HandleIncomingMessageJob::class, 3);
    }

    public function test_a_batched_whatsapp_delivery_is_answered_message_by_message(): void
    {
        $this->mockLlm();
        $this->fakeMetaApi();
        $channel = Channel::factory()->whatsapp()->create();

        $this->postSignedMetaWebhook(route('webhooks.whatsapp', $channel), $this->whatsAppBatch([
            ['wamid.1', 'Есть размер M?'],
            ['wamid.2', 'И сколько стоит?'],
        ]))->assertOk();

        $this->assertSame(2, Message::where('role', 'user')->count());
        $this->assertSame(2, Message::where('role', 'assistant')->count());
    }

    public function test_every_message_in_a_batched_instagram_delivery_is_processed(): void
    {
        Queue::fake();
        $channel = Channel::factory()->instagram()->create();

        $this->postSignedMetaWebhook(route('webhooks.instagram', $channel), [
            'object' => 'instagram',
            'entry' => [[
                'id' => 'IG',
                'messaging' => [
                    ['sender' => ['id' => '778899'], 'message' => ['mid' => 'mid.1', 'text' => 'Привет']],
                    ['sender' => ['id' => '778899'], 'message' => ['mid' => 'mid.2', 'text' => 'Есть доставка?']],
                ],
            ]],
        ])->assertOk();

        Queue::assertPushed(HandleIncomingMessageJob::class, 2);
    }

    /** A delivery-receipt change carries no messages and must still produce no work. */
    public function test_a_status_only_delivery_dispatches_nothing(): void
    {
        Queue::fake();
        $channel = Channel::factory()->whatsapp()->create();

        $this->postSignedMetaWebhook(route('webhooks.whatsapp', $channel), [
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'statuses' => [['id' => 'wamid.S', 'status' => 'delivered']],
            ]]]]],
        ])->assertOk();

        Queue::assertNothingPushed();
    }

    // -----------------------------------------------------------------
    // 3. Deduplication — Meta had no atomic claim at all
    // -----------------------------------------------------------------

    /**
     * The job's claim keyed on Telegram's update_id and bailed out for anything
     * without one, so two concurrent redeliveries of the same Meta message
     * raced each other to answer it.
     */
    public function test_a_redelivered_meta_message_is_answered_once(): void
    {
        $this->mockLlm();
        $this->fakeMetaApi();
        $channel = Channel::factory()->whatsapp()->create();
        $payload = $this->whatsAppBatch([['wamid.SAME', 'Привет']]);

        $this->postSignedMetaWebhook(route('webhooks.whatsapp', $channel), $payload)->assertOk();
        $this->postSignedMetaWebhook(route('webhooks.whatsapp', $channel), $payload)->assertOk();

        $this->assertSame(1, Message::where('role', 'assistant')->count());
    }

    // -----------------------------------------------------------------
    // 4. Attachments we cannot read — silence, from the customer's side
    // -----------------------------------------------------------------

    /**
     * A customer sending a voice note or a PDF used to get NOTHING back: the
     * driver returned null, the job stopped, and from their side that is
     * indistinguishable from a bot that has died.
     */
    public function test_an_unreadable_whatsapp_attachment_still_gets_an_answer(): void
    {
        $this->mockLlm('Извините, я читаю только текст и фото — напишите, пожалуйста, текстом.');
        $this->fakeMetaApi();
        $channel = Channel::factory()->whatsapp()->create();

        $this->postSignedMetaWebhook(route('webhooks.whatsapp', $channel), [
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'contacts' => [['profile' => ['name' => 'Ivan'], 'wa_id' => '15550001234']],
                'messages' => [[
                    'from' => '15550001234',
                    'id' => 'wamid.DOC',
                    'type' => 'document',
                    'document' => ['id' => 'media-1', 'filename' => 'order.pdf'],
                ]],
            ]]]]],
        ])->assertOk();

        $this->assertSame(1, Message::where('role', 'assistant')->count());
        $this->assertStringContainsString('document', Message::where('role', 'user')->firstOrFail()->content);
    }

    /** A reaction is not a question, so it stays silent. */
    public function test_a_whatsapp_reaction_is_still_ignored(): void
    {
        $this->fakeMetaApi();
        $channel = Channel::factory()->whatsapp()->create();

        $this->postSignedMetaWebhook(route('webhooks.whatsapp', $channel), [
            'object' => 'whatsapp_business_account',
            'entry' => [['id' => 'WABA', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp',
                'messages' => [[
                    'from' => '15550001234',
                    'id' => 'wamid.REACT',
                    'type' => 'reaction',
                    'reaction' => ['message_id' => 'wamid.1', 'emoji' => '👍'],
                ]],
            ]]]]],
        ])->assertOk();

        $this->assertSame(0, Message::count());
    }

    public function test_an_unreadable_instagram_attachment_still_gets_an_answer(): void
    {
        $this->mockLlm('Извините, я читаю только текст и фото.');
        $this->fakeMetaApi();
        $channel = Channel::factory()->instagram()->create();

        $this->postSignedMetaWebhook(route('webhooks.instagram', $channel), [
            'object' => 'instagram',
            'entry' => [['id' => 'IG', 'messaging' => [[
                'sender' => ['id' => '778899'],
                'message' => [
                    'mid' => 'mid.VIDEO',
                    'attachments' => [['type' => 'video', 'payload' => ['url' => 'https://cdn.test/v.mp4']]],
                ],
            ]]]],
        ])->assertOk();

        $this->assertSame(1, Message::where('role', 'assistant')->count());
    }

    /** An unsend/reaction event carries neither text nor attachment. */
    public function test_an_instagram_event_with_nothing_in_it_is_ignored(): void
    {
        $channel = Channel::factory()->instagram()->create();

        $this->postSignedMetaWebhook(route('webhooks.instagram', $channel), [
            'object' => 'instagram',
            'entry' => [['id' => 'IG', 'messaging' => [[
                'sender' => ['id' => '778899'],
                'message' => ['mid' => 'mid.GONE', 'is_deleted' => true],
            ]]]],
        ])->assertOk();

        $this->assertSame(0, Message::count());
    }

    // -----------------------------------------------------------------
    // 5. The messaging window — replies recorded but never sent
    // -----------------------------------------------------------------

    /**
     * The operator types a reply, the driver returns silently because the
     * 24-hour window has closed, and the inbox records it as sent. The
     * transcript then shows the owner a message the customer never received —
     * the exact failure the controller's own docblock says it exists to
     * prevent.
     */
    public function test_an_operator_reply_outside_the_window_is_refused_and_not_recorded(): void
    {
        $channel = Channel::factory()->whatsapp()->create();
        $owner = $channel->agent->user;

        $conversation = $channel->agent->conversations()->create([
            'channel_id' => $channel->id,
            'external_user_id' => '15550001234',
            'status' => 'ai',
        ]);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Здравствуйте'])
            ->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->actingAs($owner)
            ->postJson(route('conversations.reply', $conversation), ['content' => 'Извините за задержку!'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('conversations.errors.outside_window'));

        $this->assertSame(0, Message::where('role', 'operator')->count());
    }

    public function test_an_operator_reply_inside_the_window_still_goes_through(): void
    {
        $this->fakeMetaApi();
        $channel = Channel::factory()->whatsapp()->create();
        $owner = $channel->agent->user;

        $conversation = $channel->agent->conversations()->create([
            'channel_id' => $channel->id,
            'external_user_id' => '15550001234',
            'status' => 'ai',
        ]);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Здравствуйте']);

        $this->actingAs($owner)
            ->postJson(route('conversations.reply', $conversation), ['content' => 'Уже отправляем!'])
            ->assertCreated();

        $this->assertSame(1, Message::where('role', 'operator')->count());
    }

    // -----------------------------------------------------------------
    // 6. Rejected sends — 4xx read as success
    // -----------------------------------------------------------------

    /**
     * Only 5xx was checked, so every business-level rejection Meta returns with
     * a 400 — expired token, number not on WhatsApp, template violation — was
     * read as a delivered message.
     */
    public function test_a_rejected_whatsapp_send_raises_rather_than_reporting_success(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token', 'code' => 190],
            ], 401),
        ]);

        $channel = Channel::factory()->whatsapp()->create();
        $conversation = $channel->agent->conversations()->create([
            'channel_id' => $channel->id,
            'external_user_id' => '15550001234',
            'status' => 'ai',
        ]);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Здравствуйте']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid OAuth access token');

        app(WhatsAppDriver::class)
            ->sendMessage($channel, '15550001234', 'Ответ');
    }

    public function test_a_rejected_instagram_send_raises_too(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'This person is not available right now', 'code' => 10],
            ], 400),
        ]);

        $channel = Channel::factory()->instagram()->create();
        $conversation = $channel->agent->conversations()->create([
            'channel_id' => $channel->id,
            'external_user_id' => '778899',
            'status' => 'ai',
        ]);
        $conversation->messages()->create(['role' => 'user', 'content' => 'Привет']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This person is not available right now');

        app(InstagramDriver::class)
            ->sendMessage($channel, '778899', 'Ответ');
    }

    // -----------------------------------------------------------------
    // 7. Feature parity — degrade honestly, never pretend
    // -----------------------------------------------------------------

    /** Neither Meta platform can carry a per-message rating control. */
    public function test_neither_meta_channel_offers_a_rating_control(): void
    {
        $drivers = app(ChannelDriverManager::class);

        $this->assertFalse($drivers->driver('whatsapp')->supportsMessageFeedback());
        $this->assertFalse($drivers->driver('instagram')->supportsMessageFeedback());
        $this->assertTrue($drivers->driver('telegram')->supportsMessageFeedback());
    }

    /** And a 👍/👎 callback can never arrive from them, so none is parsed. */
    public function test_neither_meta_channel_parses_a_feedback_vote(): void
    {
        $drivers = app(ChannelDriverManager::class);

        foreach (['whatsapp', 'instagram'] as $type) {
            $channel = Channel::factory()->{$type}()->create();

            $this->assertNull($drivers->driver($type)->parseFeedback(['callback_query' => ['data' => 'fb:up:1']], $channel));
        }
    }

    /**
     * Lead capture is platform-agnostic — it reads the transcript, not the
     * channel — so an order placed over WhatsApp is a real lead.
     */
    public function test_a_lead_captured_over_whatsapp_is_recorded_like_any_other(): void
    {
        $this->fakeMetaApi();
        $channel = Channel::factory()->whatsapp()->create();

        $conversation = $channel->agent->conversations()->create([
            'channel_id' => $channel->id,
            'external_user_id' => '15550001234',
            'status' => 'ai',
        ]);

        $lead = $channel->agent->leads()->create([
            'conversation_id' => $conversation->id,
            'customer_name' => 'Иван',
            'contact' => '15550001234',
            'items' => 'Платье',
            'status' => 'new',
            'type' => 'order',
        ]);

        $this->assertDatabaseHas('leads', ['id' => $lead->id, 'conversation_id' => $conversation->id]);
    }
}
