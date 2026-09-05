<?php

namespace App\Services\Channels;

use App\Models\Channel;
use App\Services\Llm\LlmProviderInterface;
use App\Services\Voice\VoiceTranscriber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Telegram Bot API driver. Webhook payload format: https://core.telegram.org/bots/api#update
 */
class TelegramDriver implements ChannelDriverInterface
{
    use DescribesImagesWithConfidence, SplitsLongMessages;

    protected const MAX_MESSAGE_LENGTH = 4096;

    /**
     * Room left for the HTML tags the conversion adds after the split. A chunk
     * measured at the raw limit could cross it once "**x**" becomes "<b>x</b>".
     */
    protected const FORMATTING_MARGIN = 256;

    public function __construct(
        protected LlmProviderInterface $llm,
        protected VoiceTranscriber $transcriber,
        protected OutboundMarkdown $markdown = new OutboundMarkdown,
    ) {}

    public function verifyWebhook(Request $request, Channel $channel): ?Response
    {
        // Telegram has no GET verification handshake.
        return null;
    }

    public function authenticateIncomingRequest(Request $request, Channel $channel): bool
    {
        $expected = $channel->settings['webhook_secret'] ?? null;

        if (empty($expected)) {
            return false;
        }

        return hash_equals($expected, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'));
    }

    public function parseIncomingMessage(array $payload, Channel $channel): ?IncomingMessage
    {
        $update = $payload;
        $updateId = $update['update_id'] ?? null;
        $message = $update['message'] ?? null;

        if ($updateId === null || ! is_array($message)) {
            return null;
        }

        $from = $message['from'] ?? [];
        $externalUserId = isset($from['id']) ? (string) $from['id'] : '';

        if ($externalUserId === '') {
            return null;
        }

        $externalUserName = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? '')) ?: ($from['username'] ?? null);

        if (isset($message['text'])) {
            return new IncomingMessage((string) $updateId, $externalUserId, $externalUserName, $message['text'], 'text');
        }

        if (isset($message['photo'])) {
            $caption = trim((string) ($message['caption'] ?? ''));

            // A caption is the customer telling us what they mean — trust it over
            // (or alongside) an uncertain vision read, so we never ask "what product?"
            // when they already said. Only fall back to vision when there's no caption.
            if ($caption !== '') {
                $vision = $this->describePhoto($channel, $message);
                $text = $vision['confident'] ?? false
                    ? "[Customer sent an image with caption: \"{$caption}\". Identified in image: {$vision['description']}]"
                    : "[Customer sent an image with caption: \"{$caption}\"]";

                return new IncomingMessage((string) $updateId, $externalUserId, $externalUserName, $text, 'photo');
            }

            $vision = $this->describePhoto($channel, $message);

            if (! ($vision['confident'] ?? false)) {
                return new IncomingMessage((string) $updateId, $externalUserId, $externalUserName, '', 'photo_unclear');
            }

            return new IncomingMessage(
                (string) $updateId,
                $externalUserId,
                $externalUserName,
                "[Customer sent an image. Identified: {$vision['description']}]",
                'photo'
            );
        }

        if (isset($message['voice']) && is_array($message['voice'])) {
            return $this->handleVoice($channel, $message['voice'], (string) $updateId, $externalUserId, $externalUserName);
        }

        // Unsupported update/message type (stickers, channel posts, etc.) — ignore.
        return null;
    }

    /**
     * A voice note becomes an ordinary text turn carrying its transcript, so the
     * reply pipeline downstream never learns it was spoken — same RAG, same
     * rules, same persona, same language handling.
     *
     * Every failure mode returns a typed IncomingMessage rather than throwing:
     * the customer must always get an answer, and the job must not die holding a
     * message it already stored. The bracketed placeholders mirror the existing
     * photo convention so the model still has context for what happened.
     *
     * @param  array<string, mixed>  $voice
     */
    protected function handleVoice(
        Channel $channel,
        array $voice,
        string $updateId,
        string $externalUserId,
        ?string $externalUserName,
    ): IncomingMessage {
        $duration = isset($voice['duration']) ? (int) $voice['duration'] : null;

        $message = fn (string $text, string $type) => new IncomingMessage(
            $updateId, $externalUserId, $externalUserName, $text, $type, $duration
        );

        $agent = $channel->agent;

        if ($agent && ! $agent->transcribe_voice) {
            return $message('[Customer sent a voice message. Voice transcription is disabled for this agent.]', 'voice_disabled');
        }

        $maxDuration = (int) config('agent.voice.max_duration_seconds', 300);

        // Checked before the download, so an hour-long note costs nothing.
        if ($duration !== null && $duration > $maxDuration) {
            return $message(
                "[Customer sent a voice message of {$duration} seconds, which is longer than this agent accepts.]",
                'voice_too_long'
            );
        }

        $maxBytes = (int) config('agent.voice.max_file_bytes', 20 * 1024 * 1024);

        if (isset($voice['file_size']) && (int) $voice['file_size'] > $maxBytes) {
            return $message('[Customer sent a voice message that is too large to process.]', 'voice_too_long');
        }

        $tempPath = null;

        try {
            $tempPath = $this->downloadVoice($channel, (string) ($voice['file_id'] ?? ''), $maxBytes);

            $transcript = $this->transcriber->transcribe($tempPath, $this->transcriber->languageHintFor($agent?->language));

            return $message($transcript, 'voice');
        } catch (\Throwable $e) {
            // Agent id is the thing that makes this actionable for the owner.
            Log::warning('Voice transcription failed', [
                'agent_id' => $agent?->id,
                'channel_id' => $channel->id,
                'duration' => $duration,
                'error' => $e->getMessage(),
            ]);

            return $message('[Customer sent a voice message that could not be transcribed.]', 'voice_failed');
        } finally {
            // The audio is never kept: transcribe and discard, including the
            // optional converted copy ffmpeg may have written beside it.
            if ($tempPath !== null) {
                @unlink($tempPath);
                @unlink($tempPath.'.mp3');
            }
        }
    }

    /**
     * Resolve the file id and stream it to a temp file.
     *
     * @throws RuntimeException when Telegram will not give us the file.
     */
    protected function downloadVoice(Channel $channel, string $fileId, int $maxBytes): string
    {
        if ($fileId === '') {
            throw new RuntimeException('Voice message carried no file_id.');
        }

        $token = $this->token($channel);
        $fileInfo = $this->call($token, 'getFile', ['file_id' => $fileId]);
        $filePath = $fileInfo['result']['file_path'] ?? null;

        if (! $filePath) {
            $description = $fileInfo['description'] ?? 'no file_path returned';

            throw new RuntimeException("Telegram getFile failed: {$description}");
        }

        // Telegram reports the size here too; trust it before spending bandwidth.
        $reportedSize = (int) ($fileInfo['result']['file_size'] ?? 0);

        if ($reportedSize > $maxBytes) {
            throw new RuntimeException("Voice file is {$reportedSize} bytes, over the limit.");
        }

        $response = Http::timeout((int) config('agent.voice.timeout', 60))
            ->get("https://api.telegram.org/file/bot{$token}/{$filePath}");

        if ($response->failed()) {
            // Deliberately without the URL: it embeds the bot token.
            throw new RuntimeException("Voice file download failed with status {$response->status()}.");
        }

        $body = $response->body();

        if (strlen($body) > $maxBytes) {
            throw new RuntimeException('Voice file exceeded the size limit after download.');
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'oga';
        $tempPath = tempnam(sys_get_temp_dir(), 'velora-voice-').'.'.$extension;

        if (file_put_contents($tempPath, $body) === false) {
            throw new RuntimeException('Could not write the voice file to a temp path.');
        }

        return $tempPath;
    }

    /**
     * @return array{confident: bool, description: ?string}
     */
    protected function describePhoto(Channel $channel, array $message): array
    {
        $unclear = ['confident' => false, 'description' => null];

        try {
            $sizes = $message['photo'];
            $largest = end($sizes);
            $fileId = $largest['file_id'] ?? null;

            if (! $fileId) {
                return $unclear;
            }

            $token = $this->token($channel);
            $fileInfo = $this->call($token, 'getFile', ['file_id' => $fileId]);
            $filePath = $fileInfo['result']['file_path'] ?? null;

            if (! $filePath) {
                return $unclear;
            }

            $response = Http::timeout(15)->get("https://api.telegram.org/file/bot{$token}/{$filePath}");

            if ($response->failed()) {
                return $unclear;
            }

            $extension = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
            $mime = match (strtolower($extension)) {
                'png' => 'image/png',
                'webp' => 'image/webp',
                default => 'image/jpeg',
            };

            return $this->describeImageBytes($response->body(), $mime);
        } catch (\Throwable) {
            return $unclear;
        }
    }

    /**
     * Sent as HTML, converted from the Markdown the model writes.
     *
     * The conversion runs PER CHUNK, after splitting, so every tag a chunk opens
     * it also closes — Telegram rejects a message with an unbalanced tag, and a
     * rejected message is one the customer never sees. A bold span that happens
     * to straddle a chunk boundary simply is not converted; the worst case is a
     * literal "**", which is where this started and is survivable.
     *
     * If Telegram refuses the HTML anyway, the same chunk goes again as plain
     * text with the markup stripped. Formatting is a nicety; delivery is not.
     */
    public function sendMessage(Channel $channel, string $externalUserId, string $text): void
    {
        $token = $this->token($channel);

        foreach ($this->splitMessage($text, self::MAX_MESSAGE_LENGTH - self::FORMATTING_MARGIN) as $chunk) {
            // Formatting must never be the reason a message is not delivered.
            // A conversion that throws costs the customer their answer, which is
            // an absurd price for a bold product name.
            try {
                $formatted = $this->markdown->toTelegramHtml($chunk);
            } catch (\Throwable $e) {
                Log::warning('Markdown conversion failed; sending the raw text', [
                    'channel_id' => $channel->id,
                    'error' => $e->getMessage(),
                ]);

                $this->call($token, 'sendMessage', ['chat_id' => $externalUserId, 'text' => $chunk]);

                continue;
            }

            $response = $this->call($token, 'sendMessage', [
                'chat_id' => $externalUserId,
                'text' => $formatted,
                'parse_mode' => 'HTML',
            ]);

            if (! ($response['ok'] ?? false)) {
                Log::warning('Telegram rejected a formatted message; resending it as plain text', [
                    'channel_id' => $channel->id,
                    'description' => $response['description'] ?? null,
                ]);

                $this->call($token, 'sendMessage', [
                    'chat_id' => $externalUserId,
                    'text' => $this->markdown->toPlainText($chunk),
                ]);
            }
        }
    }

    public function sendTypingIndicator(Channel $channel, string $externalUserId): void
    {
        try {
            $this->call($this->token($channel), 'sendChatAction', [
                'chat_id' => $externalUserId,
                'action' => 'typing',
            ]);
        } catch (\Throwable) {
            // Best-effort only.
        }
    }

    public function connect(Channel $channel): array
    {
        $token = $this->token($channel);

        $me = $this->call($token, 'getMe');

        if (empty($me['ok']) || empty($me['result']['username'])) {
            throw new RuntimeException('Invalid bot token — Telegram did not return bot info.');
        }

        $webhookSecret = Str::random(48);
        $webhookUrl = route('webhooks.telegram', $channel);

        $setWebhook = $this->call($token, 'setWebhook', [
            'url' => $webhookUrl,
            'secret_token' => $webhookSecret,
            'allowed_updates' => ['message'],
        ]);

        if (empty($setWebhook['ok'])) {
            $description = $setWebhook['description'] ?? 'Unknown error';
            throw new RuntimeException("Telegram rejected the webhook: {$description}");
        }

        return [
            'bot_username' => $me['result']['username'],
            'bot_first_name' => $me['result']['first_name'] ?? null,
            'webhook_secret' => $webhookSecret,
            'webhook_url' => $webhookUrl,
        ];
    }

    public function disconnect(Channel $channel): void
    {
        $token = $this->token($channel);

        $this->call($token, 'deleteWebhook');
    }

    protected function token(Channel $channel): string
    {
        $token = $channel->credentials['bot_token'] ?? null;

        if (empty($token)) {
            throw new RuntimeException('Telegram channel has no bot token configured.');
        }

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    protected function call(string $token, string $method, array $params = []): array
    {
        try {
            $response = Http::timeout(15)
                ->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post("https://api.telegram.org/bot{$token}/{$method}", $params);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Telegram API [{$method}] connection failed: {$e->getMessage()}");
        }

        // Telegram uses 4xx (400/401/403/404) for well-formed "business" errors that
        // come with a JSON {ok:false, description:...} body callers want to inspect
        // (e.g. an invalid token). Only transport-level/server errors are fatal here.
        if ($response->serverError()) {
            throw new RuntimeException("Telegram API [{$method}] failed with status {$response->status()}: {$response->body()}");
        }

        return $response->json() ?? ['ok' => false, 'description' => 'Empty response from Telegram'];
    }
}
