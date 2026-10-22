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

    public function transcribe(Request $request, Agent $agent, VoiceTranscriber $transcriber): JsonResponse
    {
        $this->authorize('update', $agent);

        $maxBytes = (int) config('agent.voice.max_file_bytes', 20 * 1024 * 1024);
        $maxDuration = (int) config('agent.voice.max_duration_seconds', 300);

        try {
            $request->validate([
                'audio' => ['required', 'file', 'max:'.(int) ($maxBytes / 1024)],

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

        if (! $this->looksLikeAudio($file)) {
            return response()->json(['message' => __('agents.playground.voice.failed')], 422);
        }

        $path = $file->getRealPath();

        try {
            $text = $transcriber->transcribe($path);
        } catch (Throwable $e) {

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
