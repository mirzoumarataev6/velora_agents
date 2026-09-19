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

class TelegramDriver implements ChannelDriverInterface
{
    use DescribesImagesWithConfidence, SplitsLongMessages;

    protected const MAX_MESSAGE_LENGTH = 4096;

    protected const FORMATTING_MARGIN = 256;

    protected const FEEDBACK_PREFIX = 'fb';

    public const ALLOWED_UPDATES = ['message', 'callback_query'];

    // Создаёт драйвер Telegram с сервисами LLM, транскрибации и markdown-конвертации
    public function __construct(
        protected LlmProviderInterface $llm,
        protected VoiceTranscriber $transcriber,
        protected OutboundMarkdown $markdown = new OutboundMarkdown,
    ) {}

    // Подтверждает вебхук Telegram (отдельной верификации не требуется)
    public function verifyWebhook(Request $request, Channel $channel): ?Response
    {
        return null;
    }

    // Проверяет подлинность входящего запроса по секретному токену Telegram
    public function authenticateIncomingRequest(Request $request, Channel $channel): bool
    {
        $expected = $channel->settings['webhook_secret'] ?? null;

        if (empty($expected)) {
            return false;
        }

        return hash_equals($expected, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'));
    }

    // Возвращает пейлоад как единственную доставку обновления
    public function splitDeliveries(array $payload): array
    {
        return [$payload];
    }

    // Преобразует обновление Telegram во внутреннее представление входящего сообщения
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

        return null;
    }

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

            $transcript = $this->transcriber->transcribe($tempPath);

            return $message($transcript, 'voice');
        } catch (\Throwable $e) {
            Log::warning('Voice transcription failed', [
                'agent_id' => $agent?->id,
                'channel_id' => $channel->id,
                'duration' => $duration,
                'error' => $e->getMessage(),
            ]);

            return $message('[Customer sent a voice message that could not be transcribed.]', 'voice_failed');
        } finally {
            if ($tempPath !== null) {
                @unlink($tempPath);
                @unlink($tempPath.'.mp3');
            }
        }
    }

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

        $reportedSize = (int) ($fileInfo['result']['file_size'] ?? 0);

        if ($reportedSize > $maxBytes) {
            throw new RuntimeException("Voice file is {$reportedSize} bytes, over the limit.");
        }

        $response = Http::timeout((int) config('agent.voice.timeout', 60))
            ->get("https://api.telegram.org/file/bot{$token}/{$filePath}");

        if ($response->failed()) {
            throw new RuntimeException("Voice file download failed with status {$response->status()}.");
        }

        $body = $response->body();

        if (strlen($body) > $maxBytes) {
            throw new RuntimeException('Voice file exceeded the size limit after download.');
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'oga';
        $reserved = tempnam(sys_get_temp_dir(), 'velora-voice-');
        $tempPath = $reserved.'.'.$extension;
        @unlink($reserved);

        if (file_put_contents($tempPath, $body) === false) {
            throw new RuntimeException('Could not write the voice file to a temp path.');
        }

        return $tempPath;
    }

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

    // Отправляет текстовое сообщение пользователю Telegram с HTML-форматированием
    public function sendMessage(Channel $channel, string $externalUserId, string $text): void
    {
        $token = $this->token($channel);

        foreach ($this->splitMessage($text, self::MAX_MESSAGE_LENGTH - self::FORMATTING_MARGIN) as $chunk) {
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

    // Сообщает, что Telegram поддерживает обратную связь по сообщениям
    public function supportsMessageFeedback(): bool
    {
        return true;
    }

    // Отправляет сообщение с клавиатурой обратной связи (лайк/дизлайк)
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

                $this->sendMessage($channel, $externalUserId, $chunk);
            }
        }
    }

    // Преобразует callback-запрос Telegram в объект обратной связи по сообщению
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

    // Подтверждает получение обратной связи, обновляя клавиатуру сообщения в Telegram
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

    protected function feedbackKeyboard(int $messageId): array
    {
        return [
            'inline_keyboard' => [[
                ['text' => '👍', 'callback_data' => self::FEEDBACK_PREFIX.':up:'.$messageId],
                ['text' => '👎', 'callback_data' => self::FEEDBACK_PREFIX.':down:'.$messageId],
            ]],
        ];
    }

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

    // Отправляет индикатор набора текста пользователю Telegram
    public function sendTypingIndicator(Channel $channel, string $externalUserId): void
    {
        try {
            $this->call($this->token($channel), 'sendChatAction', [
                'chat_id' => $externalUserId,
                'action' => 'typing',
            ]);
        } catch (\Throwable) {
        }
    }

    // Проверяет токен бота и регистрирует вебхук при подключении канала
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

    // Переустанавливает вебхук Telegram по уже сохранённым URL и секрету
    public function syncWebhook(Channel $channel): void
    {
        $url = $channel->settings['webhook_url'] ?? null;
        $secret = $channel->settings['webhook_secret'] ?? null;

        if (empty($url) || empty($secret)) {
            throw new RuntimeException('Telegram channel has no stored webhook URL/secret — reconnect it instead.');
        }

        $this->registerWebhook($this->token($channel), $url, $secret);
    }

    // Возвращает текущую информацию о вебхуке из Telegram
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

    // Отключает канал, удаляя вебхук Telegram
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

    protected function call(string $token, string $method, array $params = []): array
    {
        try {
            $response = Http::timeout(15)
                ->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post("https://api.telegram.org/bot{$token}/{$method}", $params);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Telegram API [{$method}] connection failed: {$e->getMessage()}");
        }

        if ($response->serverError()) {
            throw new RuntimeException("Telegram API [{$method}] failed with status {$response->status()}: {$response->body()}");
        }

        return $response->json() ?? ['ok' => false, 'description' => 'Empty response from Telegram'];
    }
}
