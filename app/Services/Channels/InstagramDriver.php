<?php

namespace App\Services\Channels;

use App\Models\Channel;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Http\Client\ConnectionException;
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
    use ChecksMessagingWindow, DescribesImagesWithConfidence, HasNoMessageFeedback, SplitsLongMessages, VerifiesMetaWebhook;

    protected const API_VERSION = 'v21.0';

    // Instagram Messaging enforces the same 2000-character text limit as Messenger.
    protected const MAX_MESSAGE_LENGTH = 2000;

    public function __construct(
        protected LlmProviderInterface $llm,
        protected OutboundMarkdown $markdown = new OutboundMarkdown,
    ) {}

    /**
     * One payload per messaging event in the batch. Non-message events (reads,
     * delivery receipts, reactions) are kept rather than filtered here —
     * parseIncomingMessage() already returns null for them, and dropping them
     * in two places would mean two definitions of "a message".
     */
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
            if ($text !== '') {
                return new IncomingMessage((string) $externalMessageId, (string) $externalUserId, null, $text, 'text');
            }

            // An attachment we cannot read — a video, a voice clip, a shared
            // reel or a story mention — with nothing written alongside it. The
            // customer gets told we can only handle text and photos rather than
            // getting silence, which is what returning null gave them.
            if (! empty($message['attachments'])) {
                return new IncomingMessage(
                    (string) $externalMessageId,
                    (string) $externalUserId,
                    null,
                    '[Customer sent an attachment this assistant cannot read.]',
                    'unsupported'
                );
            }

            // No text and no attachment: a reaction, an unsend, or an event
            // shape we do not handle. Nothing was asked, so nothing is said.
            return null;
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
        // Instagram enforces the same 24-hour rule as WhatsApp — outside it a
        // free-form DM is rejected, and only a HUMAN_AGENT-tagged message (a
        // permission the app has to be granted) would go through. Checked here
        // for the same reason: a refusal the operator can see beats a silent
        // 400 they cannot.
        $this->assertWithinMessagingWindow($channel, $externalUserId);

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

        // 4xx as well as 5xx — see the same fix in WhatsAppDriver. A rejected
        // DM used to be indistinguishable from a delivered one.
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
