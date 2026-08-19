<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaygroundTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_send_a_playground_message_and_receives_assistant_reply(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->andReturn([
                'text' => 'Здравствуйте! Чем можем помочь?',
                'input_tokens' => 20,
                'output_tokens' => 8,
                'finish_reason' => 'STOP',
            ]);
        });

        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->postJson(route('agents.playground.messages.store', $agent), ['message' => 'Здравствуйте']);

        $response->assertOk()
            ->assertJsonPath('user.content', 'Здравствуйте')
            ->assertJsonPath('assistant.content', 'Здравствуйте! Чем можем помочь?')
            ->assertJsonStructure(['assistant' => ['meta' => ['retrieved_chunks', 'retrieval_ms', 'generation_ms', 'total_ms']]]);

        $this->assertDatabaseHas('conversations', [
            'agent_id' => $agent->id,
            'external_user_id' => 'playground',
        ]);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_stranger_cannot_send_playground_messages_to_someone_elses_agent(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->postJson(route('agents.playground.messages.store', $agent), ['message' => 'Hijack attempt'])
            ->assertForbidden();

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_reset_clears_the_playground_conversation(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->andReturn([
                'text' => 'Reply', 'input_tokens' => 1, 'output_tokens' => 1, 'finish_reason' => 'STOP',
            ]);
        });

        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)->postJson(route('agents.playground.messages.store', $agent), ['message' => 'Hi']);
        $this->assertDatabaseCount('conversations', 1);

        $this->actingAs($user)->delete(route('agents.playground.reset', $agent));

        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
    }
}
