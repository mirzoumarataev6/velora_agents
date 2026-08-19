<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Message;
use App\Models\User;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The sandbox microphone: record in the browser, transcribe through the SAME
 * VoiceTranscriber the Telegram webhook uses, then send the transcript down the
 * ordinary sandbox message path.
 */
class PlaygroundVoiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('agent.openai.api_key', 'test-key');
        config()->set('agent.openai.base_url', 'https://api.openai.com/v1');
        config()->set('agent.voice.ffmpeg_path', null);

        $this->owner = User::factory()->create();
        $this->agent = Agent::factory()->for($this->owner)->create(['language' => 'ru']);
    }

    protected function fakeWhisper(string $transcript = 'Есть ли доставка сегодня?', int $status = 200): void
    {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => $status === 200
                ? Http::response(['text' => $transcript], 200)
                : Http::response(['error' => ['message' => 'internal boom']], $status),
        ]);
    }

    /**
     * A recording with real bytes in it.
     *
     * Not ->create(): that reports a size but writes an empty file, which
     * VoiceTranscriber rightly refuses as empty audio before it ever calls out.
     */
    protected function recording(string $name = 'recording.webm'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, str_repeat('OPUS', 512));
    }

    /** Reports a large size; content is irrelevant because the size rule fires first. */
    protected function oversizedRecording(int $kilobytes = 500): UploadedFile
    {
        return UploadedFile::fake()->create('big.webm', $kilobytes, 'audio/webm');
    }

    protected function transcribe(?UploadedFile $file = null, array $extra = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->post(
            route('agents.playground.transcribe', $this->agent),
            array_merge(['audio' => $file ?? $this->recording()], $extra)
        );
    }

    // ---------------------------------------------------------------
    // The happy path
    // ---------------------------------------------------------------

    public function test_it_transcribes_a_recording_and_returns_the_text(): void
    {
        $this->fakeWhisper('Есть ли доставка сегодня?');

        $this->transcribe()
            ->assertOk()
            ->assertJson(['text' => 'Есть ли доставка сегодня?']);

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/audio/transcriptions'));
    }

    public function test_it_accepts_what_mediarecorder_produces(): void
    {
        // Chrome emits webm/opus, Firefox ogg, Safari mp4 — all valid Whisper input.
        foreach (['clip.webm', 'clip.ogg', 'clip.mp4', 'clip.m4a'] as $name) {
            $this->fakeWhisper('ок');

            $this->transcribe($this->recording($name))
                ->assertOk()
                ->assertJsonStructure(['text']);
        }
    }

    /**
     * The language hint follows the same rule as the Telegram path: sent for
     * ru/en, omitted for Tajik and auto so Whisper detects.
     */
    public function test_the_language_hint_matches_the_telegram_rule(): void
    {
        $this->fakeWhisper();
        $this->transcribe();
        Http::assertSent(fn (ClientRequest $r) => str_contains($r->body(), 'name="language"'));

        $this->agent->update(['language' => 'tj']);
        $this->fakeWhisper();
        $this->transcribe();
        Http::assertSent(fn (ClientRequest $r) => ! str_contains($r->body(), 'name="language"'));
    }

    /**
     * The transcript is answered by the ordinary sandbox endpoint, so there is
     * one answer path and the turn is recorded as voice-originated.
     */
    public function test_the_transcript_flows_through_the_normal_sandbox_path(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->andReturn([
                'text' => 'Да, сегодня доставляем.', 'tool_calls' => [], 'input_tokens' => 5, 'output_tokens' => 5,
            ]);
        });

        $response = $this->actingAs($this->owner)->postJson(
            route('agents.playground.messages.store', $this->agent),
            ['message' => 'Есть ли доставка сегодня?', 'source_type' => 'voice', 'voice_duration' => 9]
        );

        $response->assertOk()
            ->assertJsonPath('user.source_type', 'voice')
            ->assertJsonPath('user.voice_duration', 9)
            ->assertJsonPath('assistant.content', 'Да, сегодня доставляем.');

        $stored = Message::where('role', 'user')->firstOrFail();
        $this->assertSame('voice', $stored->source_type);
        $this->assertSame(9, $stored->voice_duration);
    }

    public function test_a_typed_sandbox_message_is_still_recorded_as_text(): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->andReturn([
                'text' => 'ок', 'tool_calls' => [], 'input_tokens' => 1, 'output_tokens' => 1,
            ]);
        });

        $this->actingAs($this->owner)->postJson(
            route('agents.playground.messages.store', $this->agent),
            ['message' => 'Привет']
        )->assertOk()->assertJsonPath('user.source_type', 'text');
    }

    // ---------------------------------------------------------------
    // Guards and failures
    // ---------------------------------------------------------------

    public function test_an_oversized_recording_is_rejected_with_a_russian_message(): void
    {
        config()->set('agent.voice.max_file_bytes', 100 * 1024); // 100 KB
        $this->fakeWhisper();

        $response = $this->transcribe($this->oversizedRecording(500));

        $response->assertStatus(422)
            ->assertJson(['message' => __('agents.playground.voice.too_long')]);

        // Refused before spending anything on transcription.
        Http::assertNothingSent();
    }

    public function test_an_over_long_recording_is_rejected_by_duration(): void
    {
        config()->set('agent.voice.max_duration_seconds', 300);
        $this->fakeWhisper();

        $this->transcribe(null, ['duration' => 601])
            ->assertStatus(422)
            ->assertJson(['message' => __('agents.playground.voice.too_long')]);

        Http::assertNothingSent();
    }

    public function test_a_whisper_error_returns_a_graceful_message(): void
    {
        $this->fakeWhisper(status: 500);

        $this->transcribe()
            ->assertStatus(422)
            ->assertJson(['message' => __('agents.playground.voice.failed')]);
    }

    public function test_an_empty_transcript_is_reported_as_a_failure(): void
    {
        $this->fakeWhisper('   ');

        $this->transcribe()
            ->assertStatus(422)
            ->assertJson(['message' => __('agents.playground.voice.failed')]);
    }

    public function test_a_non_audio_upload_is_refused(): void
    {
        $this->fakeWhisper();

        $this->transcribe(UploadedFile::fake()->createWithContent('notes.pdf', '%PDF-1.4 fake'))
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_the_audio_is_required(): void
    {
        $this->actingAs($this->owner)
            ->post(route('agents.playground.transcribe', $this->agent), [])
            ->assertStatus(422);
    }

    /**
     * Whatever went wrong, the owner sees a sentence — never the API's text,
     * the key, or the word "whisper".
     */
    public function test_no_internal_detail_is_leaked(): void
    {
        $this->fakeWhisper(status: 500);

        $body = $this->transcribe()->getContent();

        foreach (['internal boom', 'test-key', 'whisper', 'openai', 'api.openai.com', 'Exception'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $body);
        }
    }

    // ---------------------------------------------------------------
    // Authorization
    // ---------------------------------------------------------------

    public function test_another_owner_cannot_use_this_agents_endpoint(): void
    {
        $this->fakeWhisper();

        $this->transcribe(as: User::factory()->create())->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_guests_are_redirected(): void
    {
        $this->post(route('agents.playground.transcribe', $this->agent), ['audio' => $this->recording()])
            ->assertRedirect();
    }

    // ---------------------------------------------------------------
    // The sandbox UI
    // ---------------------------------------------------------------

    public function test_the_sandbox_renders_the_microphone_and_its_states(): void
    {
        $response = $this->actingAs($this->owner)
            ->get(route('agents.show', ['agent' => $this->agent, 'tab' => 'playground']));

        $response->assertOk();
        $response->assertSee('startRecording()', false);
        $response->assertSee('stopRecording()', false);
        $response->assertSee('transcribeUrl', false);
        // Idle / recording / transcribing / denied copy, all in Russian.
        $response->assertSee(__('agents.playground.voice.record'), false);
        $response->assertSee(__('agents.playground.voice.recording'), false);
        $response->assertSee(__('agents.playground.voice.transcribing'), false);
        $response->assertSee(__('agents.playground.voice.denied'), false);
        // And the same voice marker the inbox uses.
        $response->assertSee("msg.source_type === 'voice'", false);
        $response->assertSee('🎤', false);
    }
}
