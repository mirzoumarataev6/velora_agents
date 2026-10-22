<?php

namespace App\Services\Channels;

use App\Models\Channel;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class WhatsAppDriver implements ChannelDriverInterface
{
    use ChecksMessagingWindow, DescribesImagesWithConfidence, HasNoMessageFeedback, SplitsLongMessages, VerifiesMetaWebhook;

    protected const API_VERSION = 'v21.0';

    protected const MAX_MESSAGE_LENGTH = 4096;

    public function __construct(
        protected LlmProviderInterface $llm,
        protected OutboundMarkdown $markdown = new OutboundMarkdown,
    ) {}

    public function splitDeliveries(array $payload): array
    {
        $deliveries = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? null;

                if (! is_array($value) || empty($value['messages'])) {
                    continue;
                }

                foreach ($value['messages'] as $message) {
                    $deliveries[] = ['entry' => [['changes' => [['value' => [
                        'messaging_product' => $value['messaging_product'] ?? 'whatsapp',
                        'metadata' => $value['metadata'] ?? [],
                        'contacts' => $value['contacts'] ?? [],
                        'messages' => [$message],
                    ]]]]]];
                }
            }
        }

        return $deliveries;
    }

    public function parseIncomingMessage(array $payload, Channel $channel): ?IncomingMessage
    {
        $value = $payload['entry'][0]['changes'][0]['value'] ?? null;

        if (! is_array($value) || empty($value['messages'][0])) {
            return null;
        }

        $message = $value['messages'][0];
        $externalUserId = $message['from'] ?? null;
        $externalMessageId = $message['id'] ?? null;

        if (empty($externalUserId) || empty($externalMessageId)) {
            return null;
        }

        $externalUserName = $value['contacts'][0]['profile']['name'] ?? null;
        $type = $message['type'] ?? 'text';

        if ($type === 'text') {
            $text = $message['text']['body'] ?? '';

            return new IncomingMessage((string) $externalMessageId, (string) $externalUserId, $externalUserName, $text, 'text');
        }

        if ($type === 'image') {
            return $this->buildPhotoMessage(
                $channel,
                (string) $externalMessageId,
                (string) $externalUserId,
                $externalUserName,
                $message['image']['id'] ?? null,
                $message['image']['mime_type'] ?? null,
                trim((string) ($message['image']['caption'] ?? ''))
            );
        }

        if (in_array($type, ['reaction', 'system', 'unknown'], true)) {
            return null;
        }

        return new IncomingMessage(
            (string) $externalMessageId,
            (string) $externalUserId,
            $externalUserName,
            "[Customer sent a {$type} message, which this assistant cannot read.]",
            'unsupported'
        );
    }

    protected function buildPhotoMessage(
        Channel $channel,
        string $externalMessageId,
        string $externalUserId,
        ?string $externalUserName,
        ?string $mediaId,
        ?string $mimeType,
        string $caption
    ): IncomingMessage {
        if ($caption !== '') {
            $vision = $mediaId ? $this->describeMedia($channel, $mediaId, $mimeType) : ['confident' => false];
            $text = ($vision['confident'] ?? false)
                ? "[Customer sent an image with caption: \"{$caption}\". Identified in image: {$vision['description']}]"
                : "[Customer sent an image with caption: \"{$caption}\"]";

            return new IncomingMessage($externalMessageId, $externalUserId, $externalUserName, $text, 'photo');
        }

        $vision = $mediaId ? $this->describeMedia($channel, $mediaId, $mimeType) : ['confident' => false];

        if (! ($vision['confident'] ?? false)) {
            return new IncomingMessage($externalMessageId, $externalUserId, $externalUserName, '', 'photo_unclear');
        }

        return new IncomingMessage(
            $externalMessageId,
            $externalUserId,
            $externalUserName,
            "[Customer sent an image. Identified: {$vision['description']}]",
            'photo'
        );
    }

    protected function describeMedia(Channel $channel, string $mediaId, ?string $mimeType): array
    {
        $unclear = ['confident' => false, 'description' => null];

        try {
            $token = $this->accessToken($channel);

            $meta = Http::withToken($token)->timeout(15)->get($this->apiUrl($mediaId));

            if ($meta->failed()) {
                return $unclear;
            }

            $url = $meta->json('url');

            if (empty($url)) {
                return $unclear;
            }

            $file = Http::withToken($token)->timeout(15)->get($url);

            if ($file->failed()) {
                return $unclear;
            }

            return $this->describeImageBytes($file->body(), $mimeType ?: 'image/jpeg');
        } catch (Throwable) {
            return $unclear;
        }
    }

    public function sendMessage(Channel $channel, string $externalUserId, string $text): void
    {
        $this->assertWithinMessagingWindow($channel, $externalUserId);

        $phoneNumberId = $this->phoneNumberId($channel);
        $token = $this->accessToken($channel);

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
            $this->call($token, $phoneNumberId, [
                'messaging_product' => 'whatsapp',
                'to' => $externalUserId,
                'type' => 'text',
                'text' => ['body' => $chunk],
            ]);
        }
    }

    public function sendImage(Channel $channel, string $externalUserId, string $url, string $caption = ''): void
    {
        $this->assertWithinMessagingWindow($channel, $externalUserId);
        $result = $this->call($this->accessToken($channel), $this->phoneNumberId($channel), [
            'messaging_product' => 'whatsapp', 'to' => $externalUserId, 'type' => 'image',
            'image' => array_filter(['link' => $url, 'caption' => mb_substr($caption, 0, 900)], fn ($v) => $v !== ''),
        ]);
        if (empty($result['messages'][0]['id'])) {
            throw new RuntimeException('WhatsApp did not confirm image delivery.');
        }
    }

    public function sendTypingIndicator(Channel $channel, string $externalUserId): void {}

    public function connect(Channel $channel): array
    {
        $phoneNumberId = $this->phoneNumberId($channel);
        $token = $this->accessToken($channel);

        $response = Http::withToken($token)->timeout(15)
            ->get($this->apiUrl($phoneNumberId), ['fields' => 'display_phone_number,verified_name']);

        if ($response->failed()) {
            $message = $response->json('error.message') ?? "HTTP {$response->status()}";
            throw new RuntimeException("Meta rejected the WhatsApp credentials: {$message}");
        }

        return [
            'display_phone_number' => $response->json('display_phone_number'),
            'verified_name' => $response->json('verified_name'),
            'webhook_url' => route('webhooks.whatsapp', $channel),
        ];
    }

    public function disconnect(Channel $channel): void {}

    protected function phoneNumberId(Channel $channel): string
    {
        $value = $channel->credentials['phone_number_id'] ?? null;

        if (empty($value)) {
            throw new RuntimeException('WhatsApp channel has no phone_number_id configured.');
        }

        return $value;
    }

    protected function accessToken(Channel $channel): string
    {
        $value = $channel->credentials['access_token'] ?? null;

        if (empty($value)) {
            throw new RuntimeException('WhatsApp channel has no access token configured.');
        }

        return $value;
    }

    protected function apiUrl(string $path): string
    {
        return 'https://graph.facebook.com/'.self::API_VERSION.'/'.$path;
    }

    protected function call(string $token, string $phoneNumberId, array $payload): array
    {
        try {
            $response = Http::withToken($token)->timeout(15)
                ->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post($this->apiUrl("{$phoneNumberId}/messages"), $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException("WhatsApp API connection failed: {$e->getMessage()}");
        }

        if ($response->failed()) {
            $error = $response->json('error.message') ?? $response->body();
            $code = $response->json('error.code');

            throw new RuntimeException(
                "WhatsApp API rejected the message (HTTP {$response->status()}".
                ($code === null ? '' : ", code {$code}").
                "): {$error}"
            );
        }

        return $response->json() ?? [];
    }
}
