<?php

namespace App\Services\Channels;

use App\Models\Channel;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Instagram Messaging API driver (Meta). Webhook payload format:
 * https://developers.facebook.com/docs/messenger-platform/instagram/webhook-events-for-instagram-messaging
 *
 * Setup is manual on Meta's side, same as WhatsAppDriver — connect() only
 * validates the credentials; the callback URL + verify token are pasted into
 * the Meta App Dashboard by the business owner (see Channels tab).
 */
class InstagramDriver implements ChannelDriverInterface
{
    use DescribesImagesWithConfidence, SplitsLongMessages, VerifiesMetaWebhook;

    protected const API_VERSION = 'v21.0';

    // Instagram Messaging enforces the same 2000-character text limit as Messenger.
    protected const MAX_MESSAGE_LENGTH = 2000;

    public function __construct(
        protected LlmProviderInterface $llm,
        protected OutboundMarkdown $markdown = new OutboundMarkdown,
    ) {}

    public function authenticateIncomingRequest(Request $request, Channel $channel): bool
    {
        // Same reasoning as WhatsAppDriver: no app_secret field is collected
        // (only ig_business_account_id/page_access_token/verify_token), so
        // X-Hub-Signature-256 can't be verified here. The unguessable channel
        // UUID in the URL is the boundary; add an app_secret field + signature
        // check here if that's tightened later.
        return true;
    }

    public function parseIncomingMessage(array $payload, Channel $channel): ?IncomingMessage
    {
        $messaging = $payload['entry'][0]['messaging'][0] ?? null;

        if (! is_array($messaging) || empty($messaging['message'])) {
            // No "message" key means this is a read/delivery/reaction event, or a
            // "changes" (comments/mentions) entry — not a direct message, ignore.
            return null;
        }

        $message = $messaging['message'];

        if (! empty($message['is_echo'])) {
            // The business's own outgoing message, echoed back by Meta — not a
            // customer message, ignore to avoid replying to ourselves.
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
            if ($text === '') {
                return null;
            }

            return new IncomingMessage((string) $externalMessageId, (string) $externalUserId, null, $text, 'text');
        }

        return $this->buildPhotoMessage((string) $externalMessageId, (string) $externalUserId, $imageUrl, $text);
    }

    protected function firstImageAttachmentUrl(array $attachments): ?string
    {
        foreach ($attachments as $attachment) {
            if (($attachment['type'] ?? null) === 'image' && ! empty($attachment['payload']['url'])) {
                return $attachment['payload']['url'];
            }
        }

        return null;
    }

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

    /**
     * @return array{confident: bool, description: ?string}
     */
    protected function describeMedia(string $imageUrl): array
    {
        $unclear = ['confident' => false, 'description' => null];

        try {
            // Attachment URLs from Meta are pre-signed, temporary CDN links —
            // no bearer token needed, unlike WhatsApp's media endpoint.
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

    public function sendMessage(Channel $channel, string $externalUserId, string $text): void
    {
        $accountId = $this->accountId($channel);
        $token = $this->accessToken($channel);

        // Instagram DMs render no formatting whatsoever, so the markers are not
        // converted, they are removed — anything left behind is punctuation the
        // customer reads as a mistake.
        // Same reasoning as the other drivers: formatting is cosmetic, delivery
        // is not.
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

    public function sendTypingIndicator(Channel $channel, string $externalUserId): void
    {
        try {
            $this->call($this->accessToken($channel), $this->accountId($channel), [
                'recipient' => ['id' => $externalUserId],
                'sender_action' => 'typing_on',
            ]);
        } catch (Throwable) {
            // Best-effort only.
        }
    }

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

    public function disconnect(Channel $channel): void
    {
        // No API to programmatically unsubscribe — done manually in the Meta
        // App Dashboard, same as WhatsAppDriver::disconnect().
    }

    protected function accountId(Channel $channel): string
    {
        $value = $channel->credentials['ig_business_account_id'] ?? null;

        if (empty($value)) {
            throw new RuntimeException('Instagram channel has no ig_business_account_id configured.');
        }

        return $value;
    }

    protected function accessToken(Channel $channel): string
    {
        $value = $channel->credentials['page_access_token'] ?? null;

        if (empty($value)) {
            throw new RuntimeException('Instagram channel has no page access token configured.');
        }

        return $value;
    }

    /**
     * IGAA-prefixed tokens ("Instagram API with Instagram Login", Meta's newer
     * flow) are Instagram User Access Tokens and are only valid against
     * graph.instagram.com. graph.facebook.com rejects them with "Cannot parse
     * access token" even though the token itself is fine. Legacy EAA-prefixed
     * Facebook Page tokens (the older "Messenger" connect flow) still go
     * through graph.facebook.com as before.
     */
    protected function apiUrl(string $token, string $path): string
    {
        $host = str_starts_with($token, 'IGAA') ? 'graph.instagram.com' : 'graph.facebook.com';

        return "https://{$host}/".self::API_VERSION.'/'.$path;
    }

    /**
     * @return array<string, mixed>
     */
    protected function call(string $token, string $accountId, array $payload): array
    {
        try {
            $response = Http::withToken($token)->timeout(15)
                ->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post($this->apiUrl($token, "{$accountId}/messages"), $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Instagram API connection failed: {$e->getMessage()}");
        }

        if ($response->serverError()) {
            throw new RuntimeException("Instagram API failed with status {$response->status()}: {$response->body()}");
        }

        return $response->json() ?? [];
    }
}
