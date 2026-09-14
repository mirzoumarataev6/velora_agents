<?php

namespace App\Services\Channels;

use App\Models\Channel;
use App\Models\Message;
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

    /**
     * Namespace for this driver's callback_data. Telegram gives every bot one
     * flat 64-byte callback_data space shared by every button it ever sends, so
     * a prefix is what keeps a feedback tap from being mistaken for whatever
     * else gets a button later.
     */
    protected const FEEDBACK_PREFIX = 'fb';

    /**
     * The update types the webhook subscribes to. callback_query is not
     * optional: Telegram sends ONLY the types named here, so without it a
     * customer's 👍 reaches nothing and the button spins forever.
     *
     * Changing this list does nothing for bots already connected — see
     * syncWebhook() and `php artisan telegram:sync-webhooks`.
     */
    public const ALLOWED_UPDATES = ['message', 'callback_query'];

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

    /**
     * Telegram delivers exactly one update per POST, so there is nothing to
     * split. Meta is the reason this method exists at all — see the interface.
     */
    public function splitDeliveries(array $payload): array
    {
        return [$payload];
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

            // No language hint: the reply language is detected per message now,
            // so pinning Whisper to one language would only make it worse at
            // the customer who switches.
            $transcript = $this->transcriber->transcribe($tempPath);

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

        // The extension has to be on the name we actually write to: ffmpeg and
        // Whisper both decide how to read the file by it.
        //
        // tempnam CREATES the file it reserves, and appending the extension
        // makes a DIFFERENT path — so the reserved one has to be released here
        // or every voice note leaves an empty file in the temp directory
        // forever. The finally block downstream only ever saw the extended name.
        $extension = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'oga';
        $reserved = tempnam(sys_get_temp_dir(), 'velora-voice-');
        $tempPath = $reserved.'.'.$extension;
        @unlink($reserved);

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

    /**
     * Telegram is the one channel that clears the bar in
     * ChannelDriverInterface::supportsMessageFeedback(): inline keyboards ride
     * along with an ordinary text message, callback_query names the message the
     * button was on, and editMessageReplyMarkup can retire the control once it
     * has been used.
     */
    public function supportsMessageFeedback(): bool
    {
        return true;
    }

    /**
     * The reply, with 👍/👎 under it.
     *
     * Long text still splits, and only the LAST chunk carries the buttons —
     * they are a verdict on the answer, and the answer is not finished until
     * the last chunk lands. Anything that goes wrong falls back to a plain
     * send: a rating control is worth nothing next to the reply itself.
     */
    public function sendMessageWithFeedback(Channel $channel, string $externalUserId, string $text, int $messageId): void
    {
        $token = $this->token($channel);
        $chunks = $this->splitMessage($text, self::MAX_MESSAGE_LENGTH - self::FORMATTING_MARGIN);
        $last = count($chunks) - 1;

        foreach ($chunks as $index => $chunk) {
            if ($index !== $last) {
                $this->sendMessage($channel, $externalUserId, $chunk);

                continue;
            }

            try {
                $formatted = $this->markdown->toTelegramHtml($chunk);
            } catch (\Throwable) {
                $formatted = $chunk;
            }

            $response = $this->call($token, 'sendMessage', [
                'chat_id' => $externalUserId,
                'text' => $formatted,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode($this->feedbackKeyboard($messageId)),
            ]);

            if (! ($response['ok'] ?? false)) {
                Log::warning('Telegram rejected a reply carrying the feedback keyboard; resending it plain', [
                    'channel_id' => $channel->id,
                    'description' => $response['description'] ?? null,
                ]);

                // Without the keyboard. A reply the customer can read and not
                // rate beats a rating control on a reply they never got.
                $this->sendMessage($channel, $externalUserId, $chunk);
            }
        }
    }

    public function parseFeedback(array $payload, Channel $channel): ?MessageFeedback
    {
        $callback = $payload['callback_query'] ?? null;

        if (! is_array($callback)) {
            return null;
        }

        $parsed = $this->parseCallbackData((string) ($callback['data'] ?? ''));

        if ($parsed === null) {
            return null;
        }

        $externalUserId = isset($callback['from']['id']) ? (string) $callback['from']['id'] : '';

        if ($externalUserId === '') {
            return null;
        }

        return new MessageFeedback(
            messageId: $parsed['message_id'],
            rating: $parsed['rating'],
            externalUserId: $externalUserId,
            platformMessageId: isset($callback['message']['message_id'])
                ? (string) $callback['message']['message_id']
                : null,
            callbackId: isset($callback['id']) ? (string) $callback['id'] : null,
        );
    }

    /**
     * Acknowledge the tap, then retire the control.
     *
     * The acknowledgement goes first and unconditionally: an unanswered
     * callback_query leaves a spinner on the customer's button for as long as
     * Telegram feels like it, and that is the one part of this the customer can
     * actually see going wrong.
     *
     * The buttons are replaced with a single, unclickable one carrying the
     * confirmation — rather than removed outright — because a control that
     * simply vanishes reads as a bug, while one that turns into "Спасибо!"
     * reads as a receipt.
     */
    public function confirmFeedback(Channel $channel, MessageFeedback $feedback, string $confirmation): void
    {
        try {
            $token = $this->token($channel);

            if ($feedback->callbackId !== null) {
                $this->call($token, 'answerCallbackQuery', [
                    'callback_query_id' => $feedback->callbackId,
                    'text' => $confirmation,
                ]);
            }

            if ($feedback->platformMessageId === null) {
                return;
            }

            $this->call($token, 'editMessageReplyMarkup', [
                'chat_id' => $feedback->externalUserId,
                'message_id' => $feedback->platformMessageId,
                'reply_markup' => json_encode([
                    'inline_keyboard' => [[[
                        'text' => $confirmation,
                        // Telegram requires a callback_data on a callback
                        // button; this one is parsed as nothing and ignored,
                        // which is what makes the button inert.
                        'callback_data' => self::FEEDBACK_PREFIX.':done',
                    ]]],
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not confirm a message rating in Telegram', [
                'channel_id' => $channel->id,
                'message_id' => $feedback->messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function feedbackKeyboard(int $messageId): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '👍', 'callback_data' => self::FEEDBACK_PREFIX.':up:'.$messageId],
                ['text' => '👎', 'callback_data' => self::FEEDBACK_PREFIX.':down:'.$messageId],
            ]],
        ];
    }

    /**
     * `fb:up:123` → the vote it encodes.
     *
     * Telegram caps callback_data at 64 bytes, which is why this is a terse
     * string rather than JSON. Anything that does not match exactly — including
     * the inert `fb:done` button a confirmed message is left wearing — parses
     * as null.
     *
     * @return array{message_id: int, rating: string}|null
     */
    protected function parseCallbackData(string $data): ?array
    {
        if (! preg_match('/^'.self::FEEDBACK_PREFIX.':(up|down):(\d+)$/', $data, $matches)) {
            return null;
        }

        return [
            'message_id' => (int) $matches[2],
            'rating' => $matches[1] === 'up' ? Message::RATING_UP : Message::RATING_DOWN,
        ];
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

        $this->registerWebhook($token, $webhookUrl, $webhookSecret);

        return [
            'bot_username' => $me['result']['username'],
            'bot_first_name' => $me['result']['first_name'] ?? null,
            'webhook_secret' => $webhookSecret,
            'webhook_url' => $webhookUrl,
        ];
    }

    /**
     * Re-register an already-connected bot's webhook with the CURRENT
     * subscription list, keeping its URL and secret.
     *
     * The subscription is stored on Telegram's side, fixed at the moment
     * setWebhook was last called. A bot connected before callback_query was
     * added to ALLOWED_UPDATES keeps receiving only messages — every 👍 tap
     * is dropped by Telegram before it reaches us — until this runs.
     */
    public function syncWebhook(Channel $channel): void
    {
        $url = $channel->settings['webhook_url'] ?? null;
        $secret = $channel->settings['webhook_secret'] ?? null;

        if (empty($url) || empty($secret)) {
            throw new RuntimeException('Telegram channel has no stored webhook URL/secret — reconnect it instead.');
        }

        $this->registerWebhook($this->token($channel), $url, $secret);
    }

    /**
     * What Telegram actually has registered for this bot.
     *
     * @return array<string, mixed>
     */
    public function webhookInfo(Channel $channel): array
    {
        return $this->call($this->token($channel), 'getWebhookInfo')['result'] ?? [];
    }

    protected function registerWebhook(string $token, string $url, string $secret): void
    {
        $setWebhook = $this->call($token, 'setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => self::ALLOWED_UPDATES,
        ]);

        if (empty($setWebhook['ok'])) {
            $description = $setWebhook['description'] ?? 'Unknown error';
            throw new RuntimeException("Telegram rejected the webhook: {$description}");
        }
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
