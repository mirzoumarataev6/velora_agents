<?php

namespace App\Services\Voice;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Speech-to-text for incoming voice notes, via OpenAI's audio/transcriptions.
 *
 * Reads the same `agent.openai.*` credentials the chat provider uses rather
 * than carrying its own — one API key, one base URL, one place to rotate them.
 * It is deliberately NOT part of LlmProviderInterface: that contract is
 * provider-neutral and Gemini has no equivalent endpoint, so putting
 * transcription behind it would create a method one implementation cannot honour.
 *
 * Throws on every failure. Callers decide what the customer hears; this class
 * never composes customer-facing text.
 */
class VoiceTranscriber
{
    public function isConfigured(): bool
    {
        return (string) config('agent.openai.api_key') !== '';
    }

    /**
     * The hint to pass for an agent pinned to one language.
     *
     * Lives here rather than in a caller because every entry point — the
     * Telegram webhook and the in-app sandbox — must make the same choice:
     * Whisper wants ISO-639-1, the app's own "tj" is not one, and Tajik has no
     * Whisper code at all, so anything but ru/en is left to auto-detection
     * rather than mislabelled.
     */

    /**
     * @param  string  $path  A readable local file. Never a URL — the caller
     *                        downloads first, so a Telegram file link (which
     *                        embeds the bot token) is never handed onwards.
     * @param  string|null  $languageHint  ISO-639-1. Improves accuracy when the
     *                                     agent is pinned to one language;
     *                                     omitted for `auto` so Whisper detects.
     *
     * @throws RuntimeException on transport failure, an API error, or empty audio.
     */
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

        // Whisper uses ISO-639-1; the app's own "tj" is not one, and Tajik has no
        // Whisper code, so it is left to auto-detection rather than mislabelled.
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
            // The body can carry the key back in an error echo, so only the
            // status and the API's own message are surfaced to the log.
            $message = $response->json('error.message') ?? 'unknown error';

            throw new RuntimeException("Transcription API returned {$response->status()}: {$message}");
        }

        $text = trim((string) $response->json('text'));

        if ($text === '') {
            // Silence, or noise Whisper could not resolve into words. Not an
            // error upstream, but there is nothing to answer.
            throw new RuntimeException('Transcription returned no text.');
        }

        return $text;
    }

    /**
     * Convert to mp3 first when an ffmpeg path is configured.
     *
     * Off by default: Whisper takes Telegram's OGG/Opus as-is. This exists for
     * environments where that turns out not to hold. A conversion failure is
     * logged and swallowed — falling back to the original file is strictly
     * better than failing the whole transcription over an optional step.
     */
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
