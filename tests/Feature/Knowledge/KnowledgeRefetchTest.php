<?php

namespace Tests\Feature\Knowledge;

use App\Jobs\ExtractTextJob;
use App\Models\Agent;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class KnowledgeRefetchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requeues_a_url_source_and_clears_old_chunks(): void
    {
        Queue::fake();

        $user = $this->actingAsUser();
        $agent = Agent::factory()->for($user)->create();
        $source = KnowledgeSource::factory()->for($agent)->create([
            'type' => 'url',
            'url' => 'https://example.com',
            'status' => 'ready',
        ]);
        foreach (range(1, 3) as $i) {
            $chunk = new KnowledgeChunk(['content' => "chunk {$i}", 'chunk_index' => $i]);
            $chunk->agent_id = $agent->id;
            $chunk->knowledge_source_id = $source->id;
            $chunk->save();
        }

        $response = $this->postJson(route('agents.knowledge.refetch', [$agent, $source]));

        $response->assertOk();
        $response->assertJsonPath('source.status', 'pending');

        $this->assertSame('pending', $source->fresh()->status);
        $this->assertSame(0, $source->chunks()->count());
        Queue::assertPushed(ExtractTextJob::class);
    }

    public function test_it_rejects_refetching_a_non_url_source(): void
    {
        Queue::fake();

        $user = $this->actingAsUser();
        $agent = Agent::factory()->for($user)->create();
        $source = KnowledgeSource::factory()->for($agent)->create(['type' => 'file', 'status' => 'ready']);

        $response = $this->postJson(route('agents.knowledge.refetch', [$agent, $source]));

        $response->assertStatus(422);
        Queue::assertNotPushed(ExtractTextJob::class);
    }

    private function actingAsUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }
}
