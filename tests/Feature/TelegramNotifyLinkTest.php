<?php

namespace Tests\Feature;

use App\Jobs\HandleIncomingMessageJob;
use App\Jobs\SendLeadNotificationJob;
use App\Models\Channel;
use App\Models\Lead;
use App\Services\Channels\ChannelDriverManager;
use App\Services\TelegramNotifyLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramNotifyLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function fakeTelegramApi(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
    }

    protected function startCommandPayload(string $code, int $userId = 777): array
    {
        return [
            'update_id' => 100,
            'message' => [
                'message_id' => 100,
                'from' => ['id' => $userId, 'first_name' => 'Owner', 'username' => 'owner_ivan'],
                'chat' => ['id' => $userId, 'type' => 'private'],
                'date' => time(),
                'text' => '/start '.TelegramNotifyLinker::START_PREFIX.$code,
            ],
        ];
    }

    public function test_start_command_links_owner_chat_and_does_not_create_a_conversation(): void
    {
        $this->fakeTelegramApi();

        $channel = Channel::factory()->create();
        $agent = $channel->agent;
        $agent->forceFill(['notify_telegram_link_code' => 'SECRETCODE123'])->save();

        $job = new HandleIncomingMessageJob($channel, $this->startCommandPayload('SECRETCODE123'));
        app()->call([$job, 'handle']);

        $agent->refresh();
        $this->assertSame('777', $agent->notify_telegram_chat_id);
        // The driver derives the display name from first/last name (falling back
        // to @username only when those are absent).
        $this->assertSame('Owner', $agent->notify_telegram_username);
        $this->assertNotNull($agent->notify_telegram_linked_at);
        $this->assertNull($agent->notify_telegram_link_code);

        // The linking command must never be treated as a customer message.
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);

        // A confirmation was sent back to the owner.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage'));
    }

    public function test_wrong_code_does_not_link_but_still_swallows_the_command(): void
    {
        $this->fakeTelegramApi();

        $channel = Channel::factory()->create();
        $agent = $channel->agent;
        $agent->forceFill(['notify_telegram_link_code' => 'RIGHTCODE'])->save();

        $job = new HandleIncomingMessageJob($channel, $this->startCommandPayload('WRONGCODE'));
        app()->call([$job, 'handle']);

        $agent->refresh();
        $this->assertNull($agent->notify_telegram_chat_id);
        // Still not a customer message.
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_lead_notification_job_sends_to_the_linked_owner_chat(): void
    {
        $this->fakeTelegramApi();

        $channel = Channel::factory()->create();
        $agent = $channel->agent;
        $agent->forceFill([
            'notify_telegram_chat_id' => '777',
            'notify_telegram_linked_at' => now(),
        ])->save();

        $lead = Lead::factory()->for($agent)->create(['customer_name' => 'Иван', 'contact' => '+992001', 'items' => 'Куртка']);

        (new SendLeadNotificationJob($lead))->handle(app(ChannelDriverManager::class));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && ($request['chat_id'] ?? null) === '777'
                && str_contains($request['text'] ?? '', 'Иван');
        });
    }

    public function test_lead_notification_job_noops_when_owner_not_linked(): void
    {
        $this->fakeTelegramApi();

        $channel = Channel::factory()->create();
        $lead = Lead::factory()->for($channel->agent)->create();

        (new SendLeadNotificationJob($lead))->handle(app(ChannelDriverManager::class));

        Http::assertNothingSent();
    }

    public function test_begin_requires_a_connected_telegram_bot(): void
    {
        $channel = Channel::factory()->create();
        $linker = app(TelegramNotifyLinker::class);

        // Connected bot → returns a deep link carrying the freshly minted code.
        $link = $linker->begin($channel->agent);
        $this->assertStringContainsString('https://t.me/test_bot?start='.TelegramNotifyLinker::START_PREFIX, $link['deep_link']);
        $this->assertNotNull($channel->agent->fresh()->notify_telegram_link_code);
    }
}
