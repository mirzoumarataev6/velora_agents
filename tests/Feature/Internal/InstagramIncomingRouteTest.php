<?php

namespace Tests\Feature\Internal;

use App\Jobs\HandleIncomingMessageJob;
use App\Models\Agent;
use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstagramIncomingRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function payload(string $channelUuid): array
    {
        return [
            'channel_uuid' => $channelUuid,
            'from_user_id' => '17841400000000123',
            'from_username' => 'ivan_customer',
            'text' => 'Есть размер M?',
            'message_id' => 'MSG-1',
        ];
    }

    public function test_dispatches_handle_incoming_message_job_for_the_channel_matching_channel_uuid(): void
    {
        Queue::fake();
        $channel = Channel::factory()->instagrapi()->create();

        // Laravel's test client defaults REMOTE_ADDR to 127.0.0.1, matching a
        // request the Python service would actually make.
        $response = $this->postJson('/internal/instagram/incoming', $this->payload($channel->uuid));

        $response->assertOk()->assertJson(['ok' => true]);
        Queue::assertPushed(HandleIncomingMessageJob::class, fn ($job) => $job->channel->id === $channel->id
            && $job->payload === $this->payload($channel->uuid));
    }

    public function test_routes_to_the_correct_channel_when_multiple_accounts_are_connected(): void
    {
        Queue::fake();
        $channelA = Channel::factory()->instagrapi()->for(Agent::factory())->create(['credentials' => ['ig_username' => 'shop_a', 'ig_password' => 'x']]);
        $channelB = Channel::factory()->instagrapi()->for(Agent::factory())->create(['credentials' => ['ig_username' => 'shop_b', 'ig_password' => 'y']]);

        $this->postJson('/internal/instagram/incoming', $this->payload($channelB->uuid))->assertOk();

        Queue::assertPushed(HandleIncomingMessageJob::class, fn ($job) => $job->channel->id === $channelB->id);
        Queue::assertNotPushed(HandleIncomingMessageJob::class, fn ($job) => $job->channel->id === $channelA->id);
    }

    public function test_rejects_requests_not_from_localhost(): void
    {
        Queue::fake();
        $channel = Channel::factory()->instagrapi()->create();

        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->postJson('/internal/instagram/incoming', $this->payload($channel->uuid));

        $response->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_returns_404_when_no_connected_channel_matches_the_uuid(): void
    {
        Queue::fake();

        // Disconnected channels don't count.
        $channel = Channel::factory()->instagrapi()->create(['status' => 'disconnected']);

        $response = $this->postJson('/internal/instagram/incoming', $this->payload($channel->uuid));

        $response->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_returns_404_for_an_unknown_channel_uuid(): void
    {
        Queue::fake();
        Channel::factory()->instagrapi()->create();

        $response = $this->postJson('/internal/instagram/incoming', $this->payload((string) Str::uuid()));

        $response->assertNotFound();
        Queue::assertNothingPushed();
    }

    public function test_validates_required_fields(): void
    {
        $this->postJson('/internal/instagram/incoming', ['text' => 'hi'])
            ->assertStatus(422);
    }
}
