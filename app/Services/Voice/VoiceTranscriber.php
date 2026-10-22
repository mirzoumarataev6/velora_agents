<?php

namespace App\Services\Voice;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class VoiceTranscriber
{
    public function isConfigured(): bool
    {
        return (string) config('agent.openai.api_key') !== '';
    }

    public function transcribe(string $path, ?string $languageHint = null): string
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Transcription is not configured: no OpenAI API key.');
        }

        if (! is_file($path) || filesize($path) === 0) {
            throw new RuntimeException('Transcription source file is missing or empty.');
        }

        $path = $this->maybeConvert($path);

        $payload = ['model' => (string) config('agent.voice.model', 'whisper-1')];

        if ($languageHint !== null && in_array($languageHint, ['ru', 'en'], true)) {
            $payload['language'] = $languageHint;
        }

        try {
            $response = Http::withToken((string) config('agent.openai.api_key'))
                ->timeout((int) config('agent.voice.timeout', 60))
                ->connectTimeout((int) config('agent.openai.connect_timeout', 10))
                ->attach('file', file_get_contents($path), basename($path))
                ->post(rtrim((string) config('agent.openai.base_url'), '/').'/audio/transcriptions', $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Transcription request failed to connect: {$e->getMessage()}");
        }

        if ($response->failed()) {

            $message = $response->json('error.message') ?? 'unknown error';

            throw new RuntimeException("Transcription API returned {$response->status()}: {$message}");
        }

        $text = trim((string) $response->json('text'));

        if ($text === '') {

            throw new RuntimeException('Transcription returned no text.');
        }

        return $text;
    }

    protected function maybeConvert(string $path): string
    {
        $ffmpeg = config('agent.voice.ffmpeg_path');

        if (empty($ffmpeg)) {
            return $path;
        }

        $target = $path.'.mp3';

        try {
            $command = sprintf(
                '%s -y -i %s -vn -ar 16000 -ac 1 -b:a 64k %s',
                escapeshellcmd((string) $ffmpeg),
                escapeshellarg($path),
                escapeshellarg($target)
            );

            exec($command.' 2>&1', $output, $exitCode);

            if ($exitCode === 0 && is_file($target) && filesize($target) > 0) {
                return $target;
            }

            Log::warning('Voice ffmpeg conversion failed; sending the original file', [
                'exit_code' => $exitCode,
            ]);
        } catch (Throwable $e) {
            Log::warning('Voice ffmpeg conversion threw; sending the original file', [
                'error' => $e->getMessage(),
            ]);
        }

        return $path;
    }
}
