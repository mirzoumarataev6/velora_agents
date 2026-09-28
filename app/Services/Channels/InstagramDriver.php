<?php

namespace App\Services\Channels;

use App\Models\Channel;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class InstagramDriver implements ChannelDriverInterface
{
    use ChecksMessagingWindow, DescribesImagesWithConfidence, HasNoMessageFeedback, SplitsLongMessages, VerifiesMetaWebhook;

    protected const API_VERSION = 'v21.0';

    protected const MAX_MESSAGE_LENGTH = 2000;

    // Создаёт драйвер Instagram с сервисом LLM и markdown-конвертацией
    public function __construct(
        protected LlmProviderInterface $llm,
        protected OutboundMarkdown $markdown = new OutboundMarkdown,
    ) {}

    // Выделяет из пейлоада вебхука отдельные доставки сообщений Instagram
    public function splitDeliveries(array $payload): array
    {
        $deliveries = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['messaging'] ?? [] as $messaging) {
                $deliveries[] = ['entry' => [[
                    'id' => $entry['id'] ?? null,
                    'messaging' => [$messaging],
                ]]];
            }
        }

        return $deliveries;
    }

    // Преобразует пейлоад Instagram во внутреннее представление входящего сообщения
    public function parseIncomingMessage(array $payload, Channel $channel): ?IncomingMessage
    {
        $messaging = $payload['entry'][0]['messaging'][0] ?? null;

        if (! is_array($messaging) || empty($messaging['message'])) {
            return null;
        }

        $message = $messaging['message'];

        if (! empty($message['is_echo'])) {
            return null;
        }

        $externalUserId = $messaging['sender']['id'] ?? null;
        $externalMessageId = $message['mid'] ?? null;

        if (empty($externalUserId) || empty($externalMessageId)) {
            return null;
        }

        $text = trim((string) ($message['text'] ?? ''));
        $imageUrl = $this->firstImageAttachmentUrl($message['attachments'] ?? []);

        if ($imageUrl === null) {
            if ($text !== '') {
                return new IncomingMessage((string) $externalMessageId, (string) $externalUserId, null, $text, 'text');
            }

            if (! empty($message['attachments'])) {
                return new IncomingMessage(
                    (string) $externalMessageId,
                    (string) $externalUserId,
                    null,
                    '[Customer sent an attachment this assistant cannot read.]',
                    'unsupported'
                );
            }

            return null;
        }

        return $this->buildPhotoMessage((string) $externalMessageId, (string) $externalUserId, $imageUrl, $text);
    }

    // Возвращает URL первого вложения-изображения среди вложений сообщения
    protected function firstImageAttachmentUrl(array $attachments): ?string
    {
        foreach ($attachments as $attachment) {
            if (($attachment['type'] ?? null) === 'image' && ! empty($attachment['payload']['url'])) {
                return $attachment['payload']['url'];
            }
        }

        return null;
    }

    // Собирает входящее сообщение с фото, добавляя подпись и распознанное описание изображения
    protected function buildPhotoMessage(string $externalMessageId, string $externalUserId, string $imageUrl, string $caption): IncomingMessage
    {
        $vision = $this->describeMedia($imageUrl);

        if ($caption !== '') {
            $text = ($vision['confident'] ?? false)
                ? "[Customer sent an image with caption: \"{$caption}\". Identified in image: {$vision['description']}]"
                : "[Customer sent an image with caption: \"{$caption}\"]";

            return new IncomingMessage($externalMessageId, $externalUserId, null, $text, 'photo');
        }

        if (! ($vision['confident'] ?? false)) {
            return new IncomingMessage($externalMessageId, $externalUserId, null, '', 'photo_unclear');
        }

        return new IncomingMessage(
            $externalMessageId,
            $externalUserId,
            null,
            "[Customer sent an image. Identified: {$vision['description']}]",
            'photo'
        );
    }

    // Скачивает изображение по URL и распознаёт его содержимое
    protected function describeMedia(string $imageUrl): array
    {
        $unclear = ['confident' => false, 'description' => null];

        try {
            $response = Http::timeout(15)->get($imageUrl);

            if ($response->failed()) {
                return $unclear;
            }

            $mime = $response->header('Content-Type') ?: 'image/jpeg';

            return $this->describeImageBytes($response->body(), $mime);
        } catch (Throwable) {
            return $unclear;
        }
    }

    // Отправляет текстовое сообщение пользователю Instagram, разбивая длинный текст на части
    public function sendMessage(Channel $channel, string $externalUserId, string $text): void
    {
        $this->assertWithinMessagingWindow($channel, $externalUserId);

        $accountId = $this->accountId($channel);
        $token = $this->accessToken($channel);

        try {
            $body = $this->markdown->toPlainText($text);
        } catch (Throwable $e) {
            Log::warning('Markdown conversion failed; sending the raw text', [
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
            ]);

            $body = $text;
        }

        foreach ($this->splitMessage($body, self::MAX_MESSAGE_LENGTH) as $chunk) {
            $this->call($token, $accountId, [
                'recipient' => ['id' => $externalUserId],
                'message' => ['text' => $chunk],
            ]);
        }
    }

    // Отправляет индикатор набора текста пользователю Instagram
    public function sendTypingIndicator(Channel $channel, string $externalUserId): void
    {
        try {
            $this->call($this->accessToken($channel), $this->accountId($channel), [
                'recipient' => ['id' => $externalUserId],
                'sender_action' => 'typing_on',
            ]);
        } catch (Throwable) {
        }
    }

    // Проверяет учётные данные и возвращает профиль Instagram-аккаунта при подключении
    public function connect(Channel $channel): array
    {
        $accountId = $this->accountId($channel);
        $token = $this->accessToken($channel);

        $response = Http::withToken($token)->timeout(15)
            ->get($this->apiUrl($token, $accountId), ['fields' => 'username,name']);

        if ($response->failed()) {
            $message = $response->json('error.message') ?? "HTTP {$response->status()}";
            throw new RuntimeException("Meta rejected the Instagram credentials: {$message}");
        }

        return [
            'ig_username' => $response->json('username'),
            'ig_name' => $response->json('name'),
            'webhook_url' => route('webhooks.instagram', $channel),
        ];
    }

    // Отключает канал Instagram (дополнительных действий не требуется)
    public function disconnect(Channel $channel): void {}

    // Возвращает ID Instagram-аккаунта канала или бросает исключение, если он не настроен
    protected function accountId(Channel $channel): string
    {
        $value = $channel->credentials['ig_business_account_id'] ?? null;

        if (empty($value)) {
            throw new RuntimeException('Instagram channel has no ig_business_account_id configured.');
        }

        return $value;
    }

    // Возвращает токен доступа страницы или бросает исключение, если он не настроен
    protected function accessToken(Channel $channel): string
    {
        $value = $channel->credentials['page_access_token'] ?? null;

        if (empty($value)) {
            throw new RuntimeException('Instagram channel has no page access token configured.');
        }

        return $value;
    }

    // Строит URL Graph API, выбирая хост в зависимости от типа токена
    protected function apiUrl(string $token, string $path): string
    {
        $host = str_starts_with($token, 'IGAA') ? 'graph.instagram.com' : 'graph.facebook.com';

        return "https://{$host}/".self::API_VERSION.'/'.$path;
    }

    // Выполняет POST-запрос к Graph API для отправки сообщения или действия
    protected function call(string $token, string $accountId, array $payload): array
    {
        try {
            $response = Http::withToken($token)->timeout(15)
                ->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post($this->apiUrl($token, "{$accountId}/messages"), $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Instagram API connection failed: {$e->getMessage()}");
        }

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();
            $code = $response->json('error.code');

            throw new RuntimeException(
                "Instagram API rejected the message (HTTP {$response->status()}".
                ($code === null ? '' : ", code {$code}").
                "): {$error}"
            );
        }

        return $response->json() ?? [];
    }
}
