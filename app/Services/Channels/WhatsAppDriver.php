<?php

namespace App\Services\Channels;

use App\Models\Channel;
use App\Models\Conversation;
use App\Services\Llm\LlmProviderInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * WhatsApp Cloud API driver (Meta). Webhook payload format:
 * https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/payload-examples
 *
 * Setup is manual on Meta's side (unlike Telegram's setWebhook API call):
 * the business owner pastes our webhook URL + a verify token they choose into
 * their Meta App Dashboard. connect() only validates the credentials; it
 * cannot register the webhook for them — see the Channels tab UI for the
 * step-by-step instructions we show them.
 */
class WhatsAppDriver implements ChannelDriverInterface
{
    use DescribesImagesWithConfidence, SplitsLongMessages, VerifiesMetaWebhook;

    protected const API_VERSION = 'v21.0';

    // Meta's documented limit for a WhatsApp text message body.
    protected const MAX_MESSAGE_LENGTH = 4096;

    // WhatsApp only allows free-form (non-template) replies within 24h of the
    // customer's last inbound message.
    protected const CUSTOMER_SERVICE_WINDOW_HOURS = 24;

    public function __construct(
        protected LlmProviderInterface $llm,
        protected OutboundMarkdown $markdown = new OutboundMarkdown,
    ) {}

    public function authenticateIncomingRequest(Request $request, Channel $channel): bool
    {
        // Meta signs webhook POST bodies with X-Hub-Signature-256, verifiable with
        // the Meta App's App Secret — a field we don't currently collect (only
        // phone_number_id/access_token/verify_token, per the connect form). The
        // unguessable channel UUID in the URL is the primary boundary here, same
        // as it is for Telegram before that driver's additional secret_token
        // layer. Collecting an app_secret field and checking the signature here
        // would be the natural hardening step if/when that's added.
        return true;
    }

    public function parseIncomingMessage(array $payload, Channel $channel): ?IncomingMessage
    {
        $value = $payload['entry'][0]['changes'][0]['value'] ?? null;

        if (! is_array($value) || empty($value['messages'][0])) {
            // No "messages" key means this is some other webhook field we don't
            // handle yet (e.g. "statuses" delivery/read receipts) — ignore.
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

        // Unsupported type for v1 (audio, video, document, location, sticker, contacts, etc.).
        return null;
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

    /**
     * @return array{confident: bool, description: ?string}
     */
    protected function describeMedia(Channel $channel, string $mediaId, ?string $mimeType): array
    {
        $unclear = ['confident' => false, 'description' => null];

        try {
            $token = $this->accessToken($channel);

            // WhatsApp media is two-step: resolve the media id to a short-lived
            // download URL, then fetch it — both calls need the same bearer token.
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
        if (! $this->isWithinCustomerServiceWindow($channel, $externalUserId)) {
            Log::warning('WhatsApp 24h customer service window has closed; free-form message not sent.', [
                'channel_id' => $channel->id,
                'external_user_id' => $externalUserId,
            ]);

            // Extension point for v2: dispatch a pre-approved template message here
            // instead (the only message type Meta allows outside the window).
            // Not implemented — see class docblock and Stage 7 acceptance notes.
            return;
        }

        $phoneNumberId = $this->phoneNumberId($channel);
        $token = $this->accessToken($channel);

        // Converted before splitting, not after: WhatsApp's markers are plain
        // characters, so a span cut across two messages degrades to a visible
        // asterisk rather than to a rejected message. Converting first keeps the
        // chunk sizes honest about what is actually sent.
        // Falls back to the model's own text if the conversion throws: visible
        // asterisks are a blemish, an undelivered message is a lost customer.
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

    /**
     * Whether the customer has messaged within the last 24 hours, per Meta's
     * customer service window policy. Free-form (non-template) messages are
     * only allowed inside this window.
     */
    protected function isWithinCustomerServiceWindow(Channel $channel, string $externalUserId): bool
    {
        $lastInbound = Conversation::where('channel_id', $channel->id)
            ->where('external_user_id', $externalUserId)
            ->first()
            ?->messages()
            ->where('role', 'user')
            ->latest()
            ->first();

        return $lastInbound !== null
            && $lastInbound->created_at->gt(now()->subHours(self::CUSTOMER_SERVICE_WINDOW_HOURS));
    }

    public function sendTypingIndicator(Channel $channel, string $externalUserId): void
    {
        // The Cloud API's typing indicator is sent by marking a specific inbound
        // message as read (POST .../messages with status=read + typing_indicator),
        // which needs that message's id — not available from this method's
        // signature (externalUserId only). Best-effort no-op, same as other
        // drivers treat this as optional.
    }

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

    public function disconnect(Channel $channel): void
    {
        // Meta has no API to programmatically unsubscribe a webhook the way
        // Telegram's deleteWebhook works — unsubscribing means removing the
        // callback URL in the Meta App Dashboard. Nothing to call here; the
        // controller clears local credentials/status.
    }

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

    /**
     * @return array<string, mixed>
     */
    protected function call(string $token, string $phoneNumberId, array $payload): array
    {
        try {
            $response = Http::withToken($token)->timeout(15)
                ->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post($this->apiUrl("{$phoneNumberId}/messages"), $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException("WhatsApp API connection failed: {$e->getMessage()}");
        }

        if ($response->serverError()) {
            throw new RuntimeException("WhatsApp API failed with status {$response->status()}: {$response->body()}");
        }

        return $response->json() ?? [];
    }
}
