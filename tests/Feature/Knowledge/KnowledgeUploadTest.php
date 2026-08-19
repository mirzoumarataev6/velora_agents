<?php

namespace Tests\Feature\Knowledge;

use App\Jobs\ExtractTextJob;
use App\Models\Agent;
use App\Models\KnowledgeSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KnowledgeUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_storing_text_creates_source_and_dispatches_extract_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('agents.knowledge.text.store', $agent), [
                'name' => 'FAQ',
                'content' => 'We are open every day from 9am to 8pm.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('knowledge_sources', [
            'agent_id' => $agent->id,
            'type' => 'text',
            'name' => 'FAQ',
            'status' => 'pending',
        ]);

        Queue::assertPushed(ExtractTextJob::class, fn (ExtractTextJob $job) => $job->source->agent_id === $agent->id);
    }

    public function test_storing_url_creates_source_and_dispatches_extract_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('agents.knowledge.url.store', $agent), [
                'url' => 'https://example.com/catalog',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('knowledge_sources', [
            'agent_id' => $agent->id,
            'type' => 'url',
            'url' => 'https://example.com/catalog',
            'status' => 'pending',
        ]);

        Queue::assertPushed(ExtractTextJob::class);
    }

    public function test_storing_file_creates_source_and_dispatches_extract_job(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $file = UploadedFile::fake()->image('catalog.jpg', 20, 20);

        $this->actingAs($user)
            ->post(route('agents.knowledge.files.store', $agent), [
                'files' => [$file],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('knowledge_sources', [
            'agent_id' => $agent->id,
            'type' => 'file',
            'original_filename' => 'catalog.jpg',
            'status' => 'pending',
        ]);

        Queue::assertPushed(ExtractTextJob::class);
    }

    public function test_stranger_cannot_upload_knowledge_to_someone_elses_agent(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();

        $this->actingAs($stranger)
            ->post(route('agents.knowledge.text.store', $agent), [
                'name' => 'Hijack',
                'content' => 'Malicious content injection attempt.',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('knowledge_sources', ['name' => 'Hijack']);
        Queue::assertNotPushed(ExtractTextJob::class);
    }

    public function test_stranger_cannot_delete_someone_elses_knowledge_source(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $agent = Agent::factory()->for($owner)->create();
        $source = KnowledgeSource::factory()->for($agent)->create();

        $this->actingAs($stranger)
            ->delete(route('agents.knowledge.destroy', ['agent' => $agent, 'knowledgeSource' => $source]))
            ->assertForbidden();

        $this->assertModelExists($source);
    }

    // --- AJAX (JSON) responses used by the in-place Alpine UI ---

    public function test_storing_text_via_ajax_returns_json_source(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson(route('agents.knowledge.text.store', $agent), [
            'name' => 'FAQ',
            'content' => 'We are open every day from 9am to 8pm.',
        ]);

        $response->assertCreated()
            ->assertJsonPath('source.name', 'FAQ')
            ->assertJsonPath('source.type', 'text')
            ->assertJsonPath('source.status', 'pending');
    }

    public function test_storing_text_via_ajax_with_invalid_data_returns_422_with_errors(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $response = $this->actingAs($user)->postJson(route('agents.knowledge.text.store', $agent), [
            'name' => '',
            'content' => 'too short',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_storing_url_via_ajax_returns_json_source(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson(route('agents.knowledge.url.store', $agent), ['url' => 'https://example.com/catalog'])
            ->assertCreated()
            ->assertJsonPath('source.type', 'url');
    }

    public function test_storing_file_via_ajax_returns_json_sources(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        $file = UploadedFile::fake()->image('catalog.jpg', 20, 20);

        $response = $this->actingAs($user)->post(route('agents.knowledge.files.store', $agent), [
            'files' => [$file],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('sources.0.type', 'file');
    }

    public function test_deleting_source_via_ajax_returns_json(): void
    {
        $user = User::factory()->create();
        $agent = Agent::factory()->for($user)->create();
        $source = KnowledgeSource::factory()->for($agent)->create();

        $this->actingAs($user)
            ->deleteJson(route('agents.knowledge.destroy', ['agent' => $agent, 'knowledgeSource' => $source]))
            ->assertOk()
            ->assertJson(['deleted' => true]);

        $this->assertModelMissing($source);
    }
}
