<?php

namespace Tests\Feature\Channels;

use App\Models\Agent;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GreenApiChannelControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function credentials(array $overrides = []): array
    {
        return array_merge([
            'id_instance' => '1101000001',
            'api_token_instance' => 'FAKEGREENAPITOKEN0123456789abcdef',
            'api_url' => 'https://1101.api.greenapi.com/',
        ], $overrides);
    }

    public function test_owner_can_connect_and_webhook_is_registered_automatically(): void
    {
        Http::fake([
            '*/getStateInstance/*' => Http::response(['stateInstance' => 'authorized'], 200),
            '*/setSettings/*' => Http::response(['saveSettings' => true], 200),
        ]);
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson(route('agents.channels.greenapi.connect', $agent), $this->credentials());

        $channel = Channel::where('agent_id', $agent->id)->where('type', 'greenapi')->first();
        $response->assertOk()
            ->assertJsonPath('channel.status', 'connected')
            ->assertJsonPath('channel.webhook_registered', true)
            ->assertJsonPath('channel.webhook_url', route('webhooks.greenapi', $channel));

        $this->assertSame('https://1101.api.greenapi.com', $channel->credentials['api_url']);
        $this->assertNotEmpty($channel->credentials['webhook_token']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'setSettings')
            && $request['webhookUrl'] === route('webhooks.greenapi', $channel));
    }

    public function test_connect_still_succeeds_but_reports_when_webhook_registration_fails(): void
    {
        Http::fake([
            '*/getStateInstance/*' => Http::response(['stateInstance' => 'authorized'], 200),
            '*/setSettings/*' => Http::response(['message' => 'Server error'], 500),
        ]);
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)->postJson(route('agents.channels.greenapi.connect', $agent), $this->credentials())
            ->assertOk()
            ->assertJsonPath('channel.status', 'connected')
            ->assertJsonPath('channel.webhook_registered', false);

        $this->assertNotNull(Channel::where('type', 'greenapi')->first()->settings['webhook_error']);
    }

    public function test_api_url_defaults_when_left_empty(): void
    {
        Http::fake([
            'https://7107.api.greenapi.com/*/getStateInstance/*' => Http::response(['stateInstance' => 'authorized'], 200),
            '*/setSettings/*' => Http::response(['saveSettings' => true], 200),
        ]);
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)->postJson(route('agents.channels.greenapi.connect', $agent), $this->credentials(['api_url' => '']))
            ->assertOk();

        $this->assertSame('https://7107.api.greenapi.com', Channel::where('type', 'greenapi')->first()->credentials['api_url']);
    }

    public function test_bad_credentials_return_422_and_leave_channel_in_error(): void
    {
        Http::fake(['*/getStateInstance/*' => Http::response('Unauthorized', 401)]);
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)->postJson(route('agents.channels.greenapi.connect', $agent), $this->credentials())
            ->assertStatus(422);

        $this->assertSame('error', Channel::where('agent_id', $agent->id)->first()->status);
    }

    public function test_connect_validates_fields(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson(route('agents.channels.greenapi.connect', $agent), ['id_instance' => 'abc', 'api_url' => 'http://insecure.example'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id_instance', 'api_token_instance', 'api_url']);
    }

    public function test_stranger_cannot_connect_for_someone_elses_agent(): void
    {
        $agent = Agent::factory()->create();

        $this->actingAs(User::factory()->create())
            ->postJson(route('agents.channels.greenapi.connect', $agent), $this->credentials())
            ->assertForbidden();
    }

    public function test_owner_can_disconnect_and_webhook_is_cleared(): void
    {
        Http::fake(['*/setSettings/*' => Http::response(['saveSettings' => true], 200)]);
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        $channel = Channel::factory()->greenApi()->for($agent)->create();

        $this->actingAs($user)
            ->deleteJson(route('agents.channels.greenapi.disconnect', ['agent' => $agent, 'channel' => $channel]))
            ->assertOk()
            ->assertJsonPath('channel.status', 'disconnected');

        $this->assertNull($channel->fresh()->credentials);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'setSettings') && $request['webhookUrl'] === '');
    }

    public function test_agent_page_renders_the_green_api_card_connected_and_disconnected(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)->get(route('agents.show', $agent))
            ->assertOk()
            ->assertSee('WhatsApp (Green API)');

        Channel::factory()->greenApi()->for($agent)->create([
            'settings' => ['webhook_registered' => false, 'webhook_error' => 'HTTP 500: Server error'],
        ]);

        $this->actingAs($user)->get(route('agents.show', $agent))
            ->assertOk()
            ->assertSee(__('agents.channels.greenapi.manual_toggle'));
    }

    public function test_green_api_and_cloud_whatsapp_channels_can_coexist_on_one_agent(): void
    {
        $agent = Agent::factory()->create();

        Channel::factory()->whatsapp()->for($agent)->create();
        Channel::factory()->greenApi()->for($agent)->create();

        $this->assertSame(2, $agent->channels()->count());
    }
}
