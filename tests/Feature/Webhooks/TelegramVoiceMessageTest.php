<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\HandleIncomingMessageJob;
use App\Models\Agent;
use App\Models\Channel;
use App\Models\Message;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Incoming Telegram voice notes: transcribe, then feed the transcript into the
 * ordinary reply pipeline as if the customer had typed it.
 *
 * The whole flow runs inside the queued job, so these drive the job directly
 * with faked Telegram and OpenAI HTTP.
 */
class TelegramVoiceMessageTest extends TestCase
{
    use RefreshDatabase;

    protected Agent $agent;

    protected Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('agent.openai.api_key', 'test-key');
        config()->set('agent.openai.base_url', 'https://api.openai.com/v1');
        config()->set('agent.voice.ffmpeg_path', null);

        $this->agent = Agent::factory()->create();
        $this->channel = Channel::factory()->for($this->agent)->create([
            'type' => 'telegram',
            'status' => 'connected',
            'credentials' => ['bot_token' => 'BOT:TOKEN'],
            'settings' => ['webhook_secret' => 'secret'],
        ]);
    }

    protected function voiceUpdate(int $updateId = 900, array $voice = []): array
    {
        return [
            'update_id' => $updateId,
            'message' => [
                'message_id' => $updateId,
                'from' => ['id' => 555, 'first_name' => 'Ivan'],
                'chat' => ['id' => 555, 'type' => 'private'],
                'date' => time(),
                'voice' => array_merge([
                    'file_id' => 'VOICE_FILE_ID',
                    'duration' => 7,
                    'mime_type' => 'audio/ogg',
                    'file_size' => 12345,
                ], $voice),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides  Extra Http::fake routes.
     */
    protected function fakeHttp(string $transcript = 'Сколько стоит доставка?', array $overrides = []): void
    {
        Http::fake(array_merge([
            'api.telegram.org/*/getFile*' => Http::response([
                'ok' => true,
                'result' => ['file_path' => 'voice/file_1.oga', 'file_size' => 12345],
            ], 200),
            'api.telegram.org/file/*' => Http::response('FAKE-OGG-BYTES', 200),
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => $transcript], 200),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ], $overrides));
    }

    protected function mockLlm(string $reply = 'Доставка по городу — 1-2 дня.'): void
    {
        $this->mock(LlmProviderInterface::class, function ($mock) use ($reply) {
            $mock->shouldReceive('embed')->andReturn(['vector' => [1, 0, 0], 'input_tokens' => 3]);
            $mock->shouldReceive('generate')->andReturn([
                'text' => $reply, 'tool_calls' => [], 'input_tokens' => 10, 'output_tokens' => 5,
            ]);
        });
    }

    /**
     * Dependencies come from the container rather than being listed here: the
     * job's handle() signature is not this test's subject, and spelling it out
     * meant every new dependency broke thirteen unrelated voice tests.
     */
    protected function runJob(array $payload): void
    {
        app()->call([new HandleIncomingMessageJob($this->channel, $payload), 'handle']);
    }

    // ---------------------------------------------------------------
    // The happy path
    // ---------------------------------------------------------------

    public function test_a_voice_note_is_transcribed_and_stored_as_the_transcript(): void
    {
        $this->fakeHttp('Сколько стоит доставка?');
        $this->mockLlm();

        $this->runJob($this->voiceUpdate());

        $userMessage = Message::where('role', 'user')->firstOrFail();

        // Stored as ordinary text — the transcript — flagged as voice-originated.
        $this->assertSame('Сколько стоит доставка?', $userMessage->content);
        $this->assertSame('voice', $userMessage->source_type);
        $this->assertSame(7, $userMessage->voice_duration);
    }

    /**
     * The point of the whole feature: a transcript is answered by the SAME
     * pipeline as typed text, not a side path.
     */
    public function test_the_transcript_goes_through_the_normal_reply_pipeline(): void
    {
        $this->fakeHttp('Сколько стоит доставка?');
        $this->mockLlm('Доставка по городу — 1-2 дня.');

        $this->runJob($this->voiceUpdate());

        $reply = Message::where('role', 'assistant')->firstOrFail();

        $this->assertSame('Доставка по городу — 1-2 дня.', $reply->content);
        // Retrieval ran, which only the full pipeline does — the cut-down
        // voice-problem path records no chunks key at all.
        $this->assertArrayHasKey('retrieved_chunks', $reply->meta);
        $this->assertArrayNotHasKey('voice_problem', $reply->meta);
    }

    public function test_the_audio_is_sent_to_whisper_and_never_kept(): void
    {
        $this->fakeHttp();
        $this->mockLlm();

        $before = glob(sys_get_temp_dir().'/velora-voice-*');

        $this->runJob($this->voiceUpdate());

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/audio/transcriptions'));

        // Transcribe and discard: no temp file survives the job.
        $this->assertSame($before, glob(sys_get_temp_dir().'/velora-voice-*'));
    }

    public function test_a_reply_is_delivered_back_to_telegram(): void
    {
        $this->fakeHttp();
        $this->mockLlm('Доставка 1-2 дня.');

        $this->runJob($this->voiceUpdate());

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/sendMessage'));
    }

    // ---------------------------------------------------------------
    // Always on
    // ---------------------------------------------------------------

    /**
     * There is no per-agent off-switch any more: an unanswered voice note reads
     * as a broken bot, so transcription is unconditional. An account whose old
     * column still says "off" is transcribed like everyone else.
     */
    public function test_voice_is_transcribed_even_for_an_agent_whose_old_column_says_off(): void
    {
        $this->agent->forceFill(['transcribe_voice' => false])->save();
        $this->fakeHttp('Сколько стоит доставка?');
        $this->mockLlm('Доставка 1-2 дня.');

        $this->runJob($this->voiceUpdate());

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/audio/transcriptions'));

        $reply = Message::where('role', 'assistant')->firstOrFail();
        $this->assertSame('Доставка 1-2 дня.', $reply->content);
        $this->assertArrayNotHasKey('voice_problem', $reply->meta);
    }

    // ---------------------------------------------------------------
    // Guards and failures — none of which may crash the conversation
    // ---------------------------------------------------------------

    public function test_an_over_long_voice_note_is_refused_before_it_is_downloaded(): void
    {
        config()->set('agent.voice.max_duration_seconds', 300);
        $this->fakeHttp();
        $this->mockLlm('Слишком длинное — напишите текстом.');

        $this->runJob($this->voiceUpdate(901, ['duration' => 601]));

        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), '/getFile'));

        $reply = Message::where('role', 'assistant')->firstOrFail();
        $this->assertSame('voice_too_long', $reply->meta['voice_problem']);
    }

    public function test_a_failed_download_still_answers_the_customer(): void
    {
        $this->fakeHttp(overrides: [
            'api.telegram.org/*/getFile*' => Http::response(['ok' => false, 'description' => 'file not found'], 400),
        ]);
        $this->mockLlm('Не разобрал — напишите текстом.');

        $this->runJob($this->voiceUpdate());

        $reply = Message::where('role', 'assistant')->firstOrFail();
        $this->assertSame('voice_failed', $reply->meta['voice_problem']);
        $this->assertSame('Не разобрал — напишите текстом.', $reply->content);
    }

    public function test_a_whisper_error_still_answers_the_customer(): void
    {
        $this->fakeHttp(overrides: [
            'api.openai.com/v1/audio/transcriptions' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);
        $this->mockLlm('Не разобрал — напишите текстом.');

        $this->runJob($this->voiceUpdate());

        $reply = Message::where('role', 'assistant')->firstOrFail();
        $this->assertSame('voice_failed', $reply->meta['voice_problem']);
    }

    public function test_an_empty_transcript_is_treated_as_a_failure(): void
    {
        $this->fakeHttp(transcript: '   ');
        $this->mockLlm('Не расслышал — напишите текстом.');

        $this->runJob($this->voiceUpdate());

        $reply = Message::where('role', 'assistant')->firstOrFail();
        $this->assertSame('voice_failed', $reply->meta['voice_problem']);
    }

    /**
     * Whatever went wrong, the customer must never see the reason — the file
     * URL in particular carries the bot token.
     */
    public function test_no_internal_detail_reaches_the_customer(): void
    {
        $this->fakeHttp(overrides: [
            'api.telegram.org/*/getFile*' => Http::response(['ok' => false, 'description' => 'boom'], 400),
        ]);
        $this->mockLlm('Извините, напишите текстом.');

        $this->runJob($this->voiceUpdate());

        $reply = Message::where('role', 'assistant')->firstOrFail();

        foreach (['BOT:TOKEN', 'api.telegram.org', 'boom', 'whisper', 'getFile'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $reply->content);
        }
    }

    /**
     * A failure still leaves a readable trace in the conversation, so the
     * operator can see the customer tried to reach them by voice.
     */
    public function test_a_failed_voice_note_is_still_recorded_as_voice(): void
    {
        $this->fakeHttp(overrides: [
            'api.openai.com/v1/audio/transcriptions' => Http::response([], 500),
        ]);
        $this->mockLlm();

        $this->runJob($this->voiceUpdate());

        $userMessage = Message::where('role', 'user')->firstOrFail();
        $this->assertSame('voice', $userMessage->source_type);
        $this->assertSame(7, $userMessage->voice_duration);
        $this->assertNotEmpty($userMessage->content);
    }

    // ---------------------------------------------------------------
    // What the operator sees
    // ---------------------------------------------------------------

    public function test_the_inbox_exposes_the_voice_flag_and_duration(): void
    {
        $this->fakeHttp('Сколько стоит доставка?');
        $this->mockLlm();
        $this->runJob($this->voiceUpdate());

        $conversation = $this->agent->conversations()->firstOrFail();

        $response = $this->actingAs($this->agent->user)
            ->getJson(route('conversations.show', $conversation));

        $response->assertOk();

        $voiceTurn = collect($response->json('messages'))->firstWhere('role', 'user');

        $this->assertSame('voice', $voiceTurn['source_type']);
        $this->assertSame(7, $voiceTurn['voice_duration']);
        // The transcript itself is the message body, so the operator reads what
        // the customer actually said.
        $this->assertSame('Сколько стоит доставка?', $voiceTurn['content']);
    }

    public function test_the_inbox_renders_a_voice_indicator(): void
    {
        $response = $this->actingAs($this->agent->user)->get(route('conversations.index'));

        $response->assertOk();
        $response->assertSee("m.source_type === 'voice'", false);
        $response->assertSee('🎤', false);
        $response->assertSee(__('conversations.thread.voice_note'), false);
        // Length is shown beside it as m:ss.
        $response->assertSee('voiceLength(m.voice_duration)', false);
    }

    /**
     * The toggle is gone from the form, and a hand-crafted post that still
     * carries it must not switch anything off — the field is not fillable and
     * is not in the request's rules, so it is simply ignored.
     */
    public function test_the_toggle_is_gone_from_the_agent_form_and_cannot_be_posted_back(): void
    {
        $owner = $this->agent->user;

        $form = $this->actingAs($owner)->get(route('agents.create'));
        $form->assertOk();
        $form->assertDontSee('name="transcribe_voice"', false);

        $this->actingAs($owner)->put(route('agents.update', $this->agent), [
            'name' => $this->agent->name,
            'tone' => 'friendly',
            'on_not_found' => 'apologize',
            'proactivity' => 'subtle',
            'transcribe_voice' => '0',
        ])->assertRedirect();

        $this->assertTrue((bool) $this->agent->refresh()->transcribe_voice);
    }

    // ---------------------------------------------------------------
    // Scope
    // ---------------------------------------------------------------

    public function test_a_text_message_is_unaffected(): void
    {
        $this->fakeHttp();
        $this->mockLlm('Привет!');

        $this->runJob([
            'update_id' => 950,
            'message' => [
                'message_id' => 950,
                'from' => ['id' => 555, 'first_name' => 'Ivan'],
                'chat' => ['id' => 555, 'type' => 'private'],
                'date' => time(),
                'text' => 'Привет',
            ],
        ]);

        $userMessage = Message::where('role', 'user')->firstOrFail();
        $this->assertSame('text', $userMessage->source_type);
        $this->assertNull($userMessage->voice_duration);
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), '/audio/transcriptions'));
    }
}
