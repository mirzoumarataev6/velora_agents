<?php

namespace App\Services\Channels;

use App\Models\Channel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class InstagrapiDriver implements ChannelDriverInterface
{
    use HasNoMessageFeedback, SplitsLongMessages;

    protected const MAX_MESSAGE_LENGTH = 1000;

    public function __construct(protected OutboundMarkdown $markdown = new OutboundMarkdown) {}

    public function verifyWebhook(Request $request, Channel $channel): ?Response
    {
        return null;
    }

    public function authenticateIncomingRequest(Request $request, Channel $channel): bool
    {
        return true;
    }

    public function splitDeliveries(array $payload): array
    {
        return [$payload];
    }

    public function parseIncomingMessage(array $payload, Channel $channel): ?IncomingMessage
    {
        $userId = (string) ($payload['from_user_id'] ?? '');
        $messageId = (string) ($payload['message_id'] ?? '');

        if ($userId === '' || $messageId === '') {
            return null;
        }

        $text = trim((string) ($payload['text'] ?? ''));
        $userName = $payload['from_username'] ?? null;

        if ($text === '') {
            return null;
        }

        return new IncomingMessage($messageId, $userId, $userName, $text, 'text');
    }

    public function sendMessage(Channel $channel, string $externalUserId, string $text): void
    {
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
            $response = $this->call('/send', [
                'channel_uuid' => $channel->uuid,
                'user_id' => $externalUserId,
                'text' => $chunk,
            ]);

            if ($response->failed() || $response->json('ok') !== true) {
                throw new RuntimeException(
                    'Instagram (instagrapi) service rejected the message: '.
                    ($response->json('error') ?? "HTTP {$response->status()}")
                );
            }
        }
    }

    public function sendImage(Channel $channel, string $externalUserId, string $url, string $caption = ''): void
    {
        throw new RuntimeException('Outbound images are not enabled for this Instagram integration.');
    }

    public function sendTypingIndicator(Channel $channel, string $externalUserId): void {}

    public function supportsVoiceMessages(): bool
    {
        return false;
    }

    public function connect(Channel $channel): array
    {
        $username = $this->credential($channel, 'ig_username');
        $password = $this->credential($channel, 'ig_password');

        try {
            $response = $this->call('/connect', [
                'channel_uuid' => $channel->uuid,
                'username' => $username,
                'password' => $password,
            ], 30);
        } catch (RuntimeException) {
            throw new RuntimeException('Instagram service is not running. Make sure composer run dev is started.');
        }

        if ($response->failed()) {
            throw new RuntimeException("Instagram service returned HTTP {$response->status()} while logging in.");
        }

        if ($response->json('ok') === true) {
            return [
                'status_instance' => 'ok',
                'username' => $username,
            ];
        }

        throw new RuntimeException(match ($response->json('error_type')) {
            'challenge_required' => 'Instagram requires verification — check your email/SMS, then try connecting again.',
            'bad_credentials' => 'Instagram rejected the username or password. Double-check them and try again.',
            default => 'Instagram login failed for an unknown reason. Try again in a few minutes.',
        });
    }

    public function disconnect(Channel $channel): void
    {
        $this->call('/disconnect', ['channel_uuid' => $channel->uuid]);
    }

    protected function credential(Channel $channel, string $key): string
    {
        $value = $channel->credentials[$key] ?? null;

        if (empty($value)) {
            throw new RuntimeException("Instagram (instagrapi) channel has no {$key} configured.");
        }

        return (string) $value;
    }

    protected function call(string $path, array $payload, int $timeout = 15): \Illuminate\Http\Client\Response
    {
        try {
            return Http::timeout($timeout)->acceptJson()->post($this->serviceUrl($path), $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException("connection failed: {$e->getMessage()}");
        }
    }

    protected function serviceUrl(string $path): string
    {
        return rtrim((string) config('agent.instagram_service.url'), '/').$path;
    }
}
