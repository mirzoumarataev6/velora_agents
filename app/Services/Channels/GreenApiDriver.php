<?php

namespace App\Services\Channels;

use App\Models\Channel;
use App\Services\Llm\LlmProviderInterface;
use App\Services\Voice\VoiceTranscriber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class GreenApiDriver implements ChannelDriverInterface
{
    use ChecksMessagingWindow, DescribesImagesWithConfidence, HasNoMessageFeedback, SplitsLongMessages;

    public const DEFAULT_API_URL = 'https://7107.api.greenapi.com';

    protected const MAX_MESSAGE_LENGTH = 4096;

    protected const INCOMING_MESSAGE = 'incomingMessageReceived';

    public function __construct(
        protected LlmProviderInterface $llm,
        protected VoiceTranscriber $transcriber,
        protected OutboundMarkdown $markdown = new OutboundMarkdown,
    ) {}

    public function verifyWebhook(Request $request, Channel $channel): ?Response
    {
        return response('OK', 200);
    }

    public function authenticateIncomingRequest(Request $request, Channel $channel): bool
    {
        $expected = (string) ($channel->credentials['id_instance'] ?? '');
        $received = (string) $request->input('instanceData.idInstance', '');

        if ($expected === '' || ! hash_equals($expected, $received)) {
            return false;
        }

        $token = $channel->credentials['webhook_token'] ?? null;

        if (empty($channel->settings['webhook_registered']) || empty($token)) {
            return true;
        }

        return hash_equals($token, Str::after((string) $request->header('Authorization', ''), 'Bearer '));
    }

    public function splitDeliveries(array $payload): array
    {
        return ($payload['typeWebhook'] ?? null) === self::INCOMING_MESSAGE ? [$payload] : [];
    }

    public function parseIncomingMessage(array $payload, Channel $channel): ?IncomingMessage
    {
        if (($payload['typeWebhook'] ?? null) !== self::INCOMING_MESSAGE) {
            return null;
        }

        $body = is_array($payload['body'] ?? null) ? $payload['body'] : $payload;

        $chatId = (string) data_get($body, 'senderData.chatId', '');
        $messageId = (string) ($body['idMessage'] ?? '');

        if (! str_ends_with($chatId, '@c.us') || $messageId === '') {
            return null;
        }

        $userId = Str::before($chatId, '@');
        $userName = (string) (data_get($body, 'senderData.senderName') ?: data_get($body, 'senderData.chatName') ?: '') ?: null;
        $data = is_array($body['messageData'] ?? null) ? $body['messageData'] : [];
        $type = (string) ($data['typeMessage'] ?? '');

        return match ($type) {
            'textMessage' => new IncomingMessage($messageId, $userId, $userName, (string) data_get($data, 'textMessageData.textMessage', ''), 'text'),
            'extendedTextMessage', 'quotedMessage' => new IncomingMessage($messageId, $userId, $userName, (string) data_get($data, 'extendedTextMessageData.text', ''), 'text'),
            'imageMessage' => $this->buildPhotoMessage($messageId, $userId, $userName, $data['fileMessageData'] ?? []),
            'audioMessage' => $this->handleVoice($channel, $messageId, $userId, $userName, $data['fileMessageData'] ?? []),
            '', 'reactionMessage', 'deletedMessage', 'editedMessage' => null,
            default => new IncomingMessage(
                $messageId,
                $userId,
                $userName,
                '[Customer sent a '.Str::beforeLast($type, 'Message').' message, which this assistant cannot read.]',
                'unsupported'
            ),
        };
    }

    protected function buildPhotoMessage(string $messageId, string $userId, ?string $userName, array $file): IncomingMessage
    {
        $caption = trim((string) ($file['caption'] ?? ''));
        $vision = $this->describeDownload($file);

        if ($caption !== '') {
            $text = $vision['confident']
                ? "[Customer sent an image with caption: \"{$caption}\". Identified in image: {$vision['description']}]"
                : "[Customer sent an image with caption: \"{$caption}\"]";

            return new IncomingMessage($messageId, $userId, $userName, $text, 'photo');
        }

        if (! $vision['confident']) {
            return new IncomingMessage($messageId, $userId, $userName, '', 'photo_unclear');
        }

        return new IncomingMessage($messageId, $userId, $userName, "[Customer sent an image. Identified: {$vision['description']}]", 'photo');
    }

    protected function describeDownload(array $file): array
    {
        $unclear = ['confident' => false, 'description' => null];

        if (empty($file['downloadUrl'])) {
            return $unclear;
        }

        try {
            $response = Http::timeout(15)->get($file['downloadUrl']);

            if ($response->failed()) {
                return $unclear;
            }

            return $this->describeImageBytes($response->body(), (string) ($file['mimeType'] ?? 'image/jpeg')) + $unclear;
        } catch (Throwable) {
            return $unclear;
        }
    }

    protected function handleVoice(Channel $channel, string $messageId, string $userId, ?string $userName, array $file): IncomingMessage
    {
        $message = fn (string $text, string $type) => new IncomingMessage($messageId, $userId, $userName, $text, $type);
        $maxBytes = (int) config('agent.voice.max_file_bytes', 20 * 1024 * 1024);
        $tempPath = null;

        try {
            if (empty($file['downloadUrl'])) {
                throw new RuntimeException('Voice message carried no downloadUrl.');
            }

            $response = Http::timeout((int) config('agent.voice.timeout', 60))->get($file['downloadUrl']);

            if ($response->failed()) {
                throw new RuntimeException("Voice file download failed with status {$response->status()}.");
            }

            if (strlen($response->body()) > $maxBytes) {
                return $message('[Customer sent a voice message that is too large to process.]', 'voice_too_long');
            }

            $extension = pathinfo((string) ($file['fileName'] ?? ''), PATHINFO_EXTENSION) ?: 'oga';
            $reserved = tempnam(sys_get_temp_dir(), 'velora-voice-');
            $tempPath = $reserved.'.'.$extension;
            @unlink($reserved);

            if (file_put_contents($tempPath, $response->body()) === false) {
                throw new RuntimeException('Could not write the voice file to a temp path.');
            }

            return $message($this->transcriber->transcribe($tempPath), 'voice');
        } catch (Throwable $e) {
            Log::warning('Voice transcription failed', [
                'agent_id' => $channel->agent_id,
                'channel_id' => $channel->id,
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

    public function sendMessage(Channel $channel, string $externalUserId, string $text): void
    {
        $this->assertWithinMessagingWindow($channel, $externalUserId);

        try {
            $body = $this->markdown->toWhatsApp($text);
        } catch (Throwable $e) {
            Log::warning('Markdown conversion failed; sending the raw text', [
                'channel_id' => $channel->id,
                'error' => $e->getMessage(),
            ]);

            $body = $text;
        }

        foreach ($this->splitMessage($body, self::MAX_MESSAGE_LENGTH) as $chunk) {
            $response = $this->call($channel, 'post', 'sendMessage', [
                'chatId' => $this->chatId($externalUserId),
                'message' => $chunk,
            ]);

            if ($response->failed()) {
                throw new RuntimeException("Green API rejected the message (HTTP {$response->status()}): {$this->errorOf($response)}");
            }
        }
    }

    public function sendImage(Channel $channel, string $externalUserId, string $url, string $caption = ''): void
    {
        $this->assertWithinMessagingWindow($channel, $externalUserId);
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $candidateName = (string) ($query['filename'] ?? basename((string) parse_url($url, PHP_URL_PATH)));
        $extension = strtolower(pathinfo($candidateName, PATHINFO_EXTENSION));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $extension = 'jpg';
        }
        $response = $this->call($channel, 'post', 'sendFileByUrl', [
            'chatId' => $this->chatId($externalUserId),
            'urlFile' => $url,
            'fileName' => 'product-image.'.$extension,
            'caption' => mb_substr($caption, 0, 900),
        ]);
        if ($response->failed() || ! $response->json('idMessage')) {
            throw new RuntimeException('Green API did not confirm image delivery: '.$this->errorOf($response));
        }
    }

    public function chatId(string $externalUserId): string
    {
        return str_contains($externalUserId, '@')
            ? $externalUserId
            : preg_replace('/\D/', '', $externalUserId).'@c.us';
    }

    public function sendTypingIndicator(Channel $channel, string $externalUserId): void {}

    public function connect(Channel $channel): array
    {
        $response = $this->call($channel, 'get', 'getStateInstance');

        if ($response->failed()) {
            throw new RuntimeException("Green API rejected the credentials (HTTP {$response->status()}) — check idInstance, apiTokenInstance and apiUrl.");
        }

        return [
            'state_instance' => $response->json('stateInstance'),
            'webhook_url' => route('webhooks.greenapi', $channel),
        ] + $this->registerWebhook($channel, Str::random(48));
    }

    public function registerWebhook(Channel $channel, string $token): array
    {
        $result = fn (bool $ok, ?string $error = null) => [
            'webhook_registered' => $ok,
            'webhook_error' => $error,
            'webhook_token' => $token,
        ];

        try {
            $response = $this->call($channel, 'post', 'setSettings', [
                'webhookUrl' => route('webhooks.greenapi', $channel),
                'webhookUrlToken' => $token,
                'incomingWebhook' => 'yes',
            ]);
        } catch (Throwable $e) {
            return $result(false, $e->getMessage());
        }

        if ($response->successful() && $response->json('saveSettings') === true) {
            return $result(true);
        }

        return $result(false, "HTTP {$response->status()}: {$this->errorOf($response)}");
    }

    public function disconnect(Channel $channel): void
    {
        $this->call($channel, 'post', 'setSettings', ['webhookUrl' => '', 'webhookUrlToken' => '']);
    }

    protected function call(Channel $channel, string $method, string $apiMethod, array $payload = []): ClientResponse
    {
        $url = sprintf(
            '%s/waInstance%s/%s/%s',
            $this->apiUrl($channel),
            $this->credential($channel, 'id_instance'),
            $apiMethod,
            $this->credential($channel, 'api_token_instance'),
        );

        try {
            return Http::timeout(15)->acceptJson()
                ->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->{$method}($url, $payload);
        } catch (ConnectionException) {
            throw new RuntimeException("Could not reach Green API ({$apiMethod}).");
        }
    }

    protected function apiUrl(Channel $channel): string
    {
        return rtrim(($channel->credentials['api_url'] ?? null) ?: self::DEFAULT_API_URL, '/');
    }

    protected function credential(Channel $channel, string $key): string
    {
        $value = $channel->credentials[$key] ?? null;

        if (empty($value)) {
            throw new RuntimeException("Green API channel has no {$key} configured.");
        }

        return (string) $value;
    }

    protected function errorOf(ClientResponse $response): string
    {
        return (string) ($response->json('message') ?? Str::limit($response->body(), 200));
    }
}
