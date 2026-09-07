<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\AgentReplyService;
use App\Services\Voice\VoiceTranscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class PlaygroundController extends Controller
{
    public function store(Request $request, Agent $agent, AgentReplyService $replyService): JsonResponse
    {
        $this->authorize('update', $agent);

        $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            // Set by the sandbox when the text came from the microphone, so the
            // turn is recorded the same way a Telegram voice note is.
            'source_type' => ['nullable', 'string', 'in:text,voice'],
            'voice_duration' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ]);

        $conversation = $this->playgroundConversation($agent);

        $sourceType = $request->input('source_type') === 'voice' ? 'voice' : 'text';

        $result = $replyService->reply(
            $conversation,
            $request->string('message'),
            sourceType: $sourceType,
            voiceDuration: $sourceType === 'voice' ? $request->integer('voice_duration') ?: null : null,
        );

        // The last onboarding step, ticked by doing the thing rather than by
        // clicking "done". Only recorded once the agent has actually answered,
        // so a failed reply doesn't credit a sandbox that never worked.
        $request->user()->markPlaygroundTried();

        return response()->json([
            'user' => [
                'id' => $result['user']->id,
                'role' => 'user',
                'content' => $result['user']->content,
                'source_type' => $result['user']->source_type,
                'voice_duration' => $result['user']->voice_duration,
            ],
            'assistant' => [
                'id' => $result['assistant']->id,
                'role' => 'assistant',
                'content' => $result['assistant']->content,
                'meta' => $result['assistant']->meta,
            ],
        ]);
    }

    /**
     * Transcribe a recording made in the browser.
     *
     * Transcription only — the transcript goes back to the sandbox, which then
     * posts it to store() above. That keeps a single answer path: the agent
     * never learns whether the owner typed or spoke, and there is no second
     * copy of the reply logic to drift.
     *
     * The audio is not persisted. PHP discards the upload when the request
     * ends; the optional ffmpeg copy is removed explicitly.
     */
    public function transcribe(Request $request, Agent $agent, VoiceTranscriber $transcriber): JsonResponse
    {
        $this->authorize('update', $agent);

        $maxBytes = (int) config('agent.voice.max_file_bytes', 20 * 1024 * 1024);
        $maxDuration = (int) config('agent.voice.max_duration_seconds', 300);

        try {
            $request->validate([
                'audio' => ['required', 'file', 'max:'.(int) ($maxBytes / 1024)],
                // Advisory: the browser knows how long it recorded. The size
                // limit above is the guard that actually caps cost.
                'duration' => ['nullable', 'integer', 'min:0'],
            ], [
                'audio.max' => __('agents.playground.voice.too_long'),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->validator->errors()->first('audio') ?: __('agents.playground.voice.failed'),
            ], 422);
        }

        if ($request->integer('duration') > $maxDuration) {
            return response()->json(['message' => __('agents.playground.voice.too_long')], 422);
        }

        $file = $request->file('audio');

        // Checked against what the browser said it is, not against sniffed
        // content: MediaRecorder output is a container the `mimetypes` rule
        // detects inconsistently (and not at all where fileinfo is missing),
        // which would reject genuine Safari and Firefox recordings. This is
        // here to refuse an obviously wrong upload — the real protections are
        // the size cap above and the owner-scoped authorize() at the top.
        if (! $this->looksLikeAudio($file)) {
            return response()->json(['message' => __('agents.playground.voice.failed')], 422);
        }

        $path = $file->getRealPath();

        try {
            $text = $transcriber->transcribe($path);
        } catch (Throwable $e) {
            // The raw reason can carry API text; it belongs in the log, never in
            // the response.
            Log::warning('Sandbox voice transcription failed', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => __('agents.playground.voice.failed')], 422);
        } finally {
            @unlink($path.'.mp3');
        }

        return response()->json(['text' => $text]);
    }

    /**
     * Everything MediaRecorder can hand us across browsers — webm/opus in
     * Chrome, ogg in Firefox, mp4/aac in Safari — plus the ordinary audio
     * formats, all of which Whisper accepts directly.
     */
    protected function looksLikeAudio(UploadedFile $file): bool
    {
        $allowedExtensions = ['webm', 'ogg', 'oga', 'opus', 'mp4', 'm4a', 'mp3', 'mpga', 'wav', 'flac'];
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if (in_array($extension, $allowedExtensions, true)) {
            return true;
        }

        $mime = strtolower((string) $file->getClientMimeType());

        return str_starts_with($mime, 'audio/') || $mime === 'video/webm';
    }

    public function reset(Agent $agent): RedirectResponse
    {
        $this->authorize('update', $agent);

        $this->playgroundConversation($agent)->delete();

        return back()->with('status', 'Диалог в песочнице очищен.');
    }

    protected function playgroundConversation(Agent $agent)
    {
        return $agent->conversations()->firstOrCreate(
            ['channel_id' => null, 'external_user_id' => 'playground'],
            ['external_user_name' => 'Песочница', 'status' => 'ai']
        );
    }
}
