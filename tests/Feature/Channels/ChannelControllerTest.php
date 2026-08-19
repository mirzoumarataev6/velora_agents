<?php

namespace Tests\Feature\Channels;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannelControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_connect_telegram_bot(): void
    {
        Http::fake([
            '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'my_shop_bot', 'first_name' => 'My Shop']]),
            '*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('agents.channels.telegram.connect', $agent), [
            'bot_token' => '123456789:AAExampleTokenString',
        ]);

        $response->assertRedirect();
        $channel = Channel::where('agent_id', $agent->id)->where('type', 'telegram')->first();
        $this->assertNotNull($channel);
        $this->assertSame('connected', $channel->status);
        $this->assertSame('my_shop_bot', $channel->settings['bot_username']);
        $this->assertSame('123456789:AAExampleTokenString', $channel->credentials['bot_token']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'setWebhook'));
    }

    public function test_webhook_url_uses_https_when_request_is_forwarded_from_a_proxy(): void
    {
        Http::fake([
            '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'proxy_bot']]),
            '*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        // Simulates the app running behind a TLS-terminating reverse proxy/tunnel
        // (ngrok in dev, a load balancer in production): the request Laravel
        // actually receives is plain HTTP, but the proxy reports the original
        // request was HTTPS via this header.
        $this->actingAs($user)
            ->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->post(route('agents.channels.telegram.connect', $agent), [
                'bot_token' => '123456789:AAExampleTokenString',
            ])
            ->assertRedirect();

        $channel = Channel::where('agent_id', $agent->id)->first();
        $this->assertStringStartsWith('https://', $channel->settings['webhook_url']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'setWebhook')) {
                return false;
            }

            return str_starts_with(data_get($request->data(), 'url', ''), 'https://');
        });
    }

    public function test_invalid_bot_token_format_fails_validation(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('agents.channels.telegram.connect', $agent), ['bot_token' => 'not-a-real-token'])
            ->assertSessionHasErrors('bot_token');

        $this->assertDatabaseCount('channels', 0);
    }

    public function test_connect_fails_gracefully_when_telegram_rejects_the_token(): void
    {
        Http::fake([
            '*/getMe' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401),
        ]);

        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $response = $this->actingAs($user)->post(route('agents.channels.telegram.connect', $agent), [
            'bot_token' => '123456789:AAInvalidToken',
        ]);

        $response->assertSessionHasErrors('bot_token');
        $channel = Channel::where('agent_id', $agent->id)->first();
        $this->assertSame('error', $channel->status);
    }

    public function test_owner_can_disconnect_telegram_bot(): void
    {
        Http::fake(['*/deleteWebhook' => Http::response(['ok' => true, 'result' => true])]);

        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        $channel = Channel::factory()->for($agent)->create();

        $this->actingAs($user)
            ->delete(route('agents.channels.telegram.disconnect', ['agent' => $agent, 'channel' => $channel]))
            ->assertRedirect();

        $channel->refresh();
        $this->assertSame('disconnected', $channel->status);
        $this->assertNull($channel->credentials);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'deleteWebhook'));
    }

    public function test_stranger_cannot_connect_telegram_for_someone_elses_agent(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->post(route('agents.channels.telegram.connect', $agent), ['bot_token' => '123456789:AAExampleTokenString'])
            ->assertForbidden();

        $this->assertDatabaseCount('channels', 0);
    }

    public function test_stranger_cannot_disconnect_someone_elses_channel(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        $channel = Channel::factory()->for($agent)->create();

        $this->actingAs($stranger)
            ->delete(route('agents.channels.telegram.disconnect', ['agent' => $agent, 'channel' => $channel]))
            ->assertForbidden();

        $this->assertSame('connected', $channel->fresh()->status);
    }

    // --- AJAX (JSON) responses used by the in-place Alpine UI ---

    public function test_connect_via_ajax_returns_json_channel(): void
    {
        Http::fake([
            '*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'ajax_bot']]),
            '*/setWebhook' => Http::response(['ok' => true, 'result' => true]),
        ]);

        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson(route('agents.channels.telegram.connect', $agent), ['bot_token' => '123456789:AAExampleTokenString'])
            ->assertOk()
            ->assertJsonPath('channel.status', 'connected')
            ->assertJsonPath('channel.bot_username', 'ajax_bot');
    }

    public function test_connect_via_ajax_with_bad_token_returns_422(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson(route('agents.channels.telegram.connect', $agent), ['bot_token' => 'garbage'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bot_token']);
    }

    public function test_disconnect_via_ajax_returns_json_channel(): void
    {
        Http::fake(['*/deleteWebhook' => Http::response(['ok' => true, 'result' => true])]);

        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        $channel = Channel::factory()->for($agent)->create();

        $this->actingAs($user)
            ->deleteJson(route('agents.channels.telegram.disconnect', ['agent' => $agent, 'channel' => $channel]))
            ->assertOk()
            ->assertJsonPath('channel.status', 'disconnected');
    }

    // --- WhatsApp ---

    public function test_owner_can_connect_whatsapp(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['display_phone_number' => '+1 555-0100', 'verified_name' => 'My Shop'], 200),
        ]);

        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson(route('agents.channels.whatsapp.connect', $agent), [
            'phone_number_id' => '1234567890',
            'access_token' => 'EAAG-fake-token',
            'verify_token' => 'my-verify-token-123',
        ]);

        $response->assertOk()->assertJsonPath('channel.status', 'connected');
        $channel = Channel::where('agent_id', $agent->id)->where('type', 'whatsapp')->first();
        $this->assertNotNull($channel);
        $this->assertSame('+1 555-0100', $channel->settings['display_phone_number']);
        $this->assertSame('1234567890', $channel->credentials['phone_number_id']);
    }

    public function test_whatsapp_connect_requires_all_credential_fields(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson(route('agents.channels.whatsapp.connect', $agent), ['phone_number_id' => '123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['access_token', 'verify_token']);
    }

    public function test_whatsapp_connect_fails_gracefully_when_meta_rejects_credentials(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);

        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson(route('agents.channels.whatsapp.connect', $agent), [
            'phone_number_id' => '1234567890',
            'access_token' => 'bad-token',
            'verify_token' => 'my-verify-token-123',
        ]);

        $response->assertStatus(422);
        $channel = Channel::where('agent_id', $agent->id)->first();
        $this->assertSame('error', $channel->status);
    }

    public function test_owner_can_disconnect_whatsapp(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        $channel = Channel::factory()->whatsapp()->for($agent)->create();

        $this->actingAs($user)
            ->deleteJson(route('agents.channels.whatsapp.disconnect', ['agent' => $agent, 'channel' => $channel]))
            ->assertOk()
            ->assertJsonPath('channel.status', 'disconnected');

        $this->assertNull($channel->fresh()->credentials);
    }

    public function test_stranger_cannot_connect_whatsapp_for_someone_elses_agent(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->postJson(route('agents.channels.whatsapp.connect', $agent), [
                'phone_number_id' => '1234567890',
                'access_token' => 'token',
                'verify_token' => 'verify-token-123',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('channels', 0);
    }

    // --- Instagram ---

    public function test_owner_can_connect_instagram(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['username' => 'my_shop_ig', 'name' => 'My Shop'], 200),
        ]);

        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson(route('agents.channels.instagram.connect', $agent), [
            'ig_business_account_id' => '9876543210',
            'page_access_token' => 'EAAG-fake-page-token',
            'verify_token' => 'my-verify-token-456',
        ]);

        $response->assertOk()->assertJsonPath('channel.status', 'connected');
        $channel = Channel::where('agent_id', $agent->id)->where('type', 'instagram')->first();
        $this->assertNotNull($channel);
        $this->assertSame('my_shop_ig', $channel->settings['ig_username']);
        $this->assertSame('9876543210', $channel->credentials['ig_business_account_id']);
    }

    public function test_instagram_connect_requires_all_credential_fields(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson(route('agents.channels.instagram.connect', $agent), ['ig_business_account_id' => '123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['page_access_token', 'verify_token']);
    }

    public function test_owner_can_disconnect_instagram(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        $channel = Channel::factory()->instagram()->for($agent)->create();

        $this->actingAs($user)
            ->deleteJson(route('agents.channels.instagram.disconnect', ['agent' => $agent, 'channel' => $channel]))
            ->assertOk()
            ->assertJsonPath('channel.status', 'disconnected');

        $this->assertNull($channel->fresh()->credentials);
    }

    // --- Channels tab rendering ---

    public function test_agent_show_page_renders_with_connected_whatsapp_and_instagram_channels(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        Channel::factory()->whatsapp()->for($agent)->create();
        Channel::factory()->instagram()->for($agent)->create();

        $this->actingAs($user)->get(route('agents.show', $agent))->assertOk();
    }

    public function test_stranger_cannot_disconnect_someone_elses_whatsapp_channel(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        $channel = Channel::factory()->whatsapp()->for($agent)->create();

        $this->actingAs($stranger)
            ->deleteJson(route('agents.channels.whatsapp.disconnect', ['agent' => $agent, 'channel' => $channel]))
            ->assertForbidden();

        $this->assertSame('connected', $channel->fresh()->status);
    }
}
