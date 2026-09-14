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
    use ChecksMessagingWindow, DescribesImagesWithConfidence, HasNoMessageFeedback, SplitsLongMessages, VerifiesMetaWebhook;

    protected const API_VERSION = 'v21.0';

    // Meta's documented limit for a WhatsApp text message body.
    protected const MAX_MESSAGE_LENGTH = 4096;

    public function __construct(
        protected LlmProviderInterface $llm,
        protected OutboundMarkdown $markdown = new OutboundMarkdown,
    ) {}

    /**
     * One payload per message in the batch, each carrying the `contacts` block
     * from its own change so the customer's display name survives the split.
     */
    public function splitDeliveries(array $payload): array
    {
        $deliveries = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? null;

                if (! is_array($value) || empty($value['messages'])) {
                    continue; // A "statuses" (delivery/read receipt) change, or something else.
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

        // A reaction or a system notice is not the customer asking us anything,
        // so it stays silent. Anything else they deliberately SENT — a voice
        // note, a video, a PDF, a pinned location — gets an answer saying we
        // can only read text and photos. Returning null here meant the customer
        // sent a document and got nothing back at all, which from their side is
        // indistinguishable from a dead bot.
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
        // Throws rather than returning quietly. Returning was the bug: the
        // caller recorded a Message row and reported success, so the transcript
        // showed the operator a reply the customer had never received, and the
        // owner had no way to find out. The exception reaches the inbox as a
        // real error and the reply pipeline as a logged failure.
        $this->assertWithinMessagingWindow($channel, $externalUserId);

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

        // 4xx as well as 5xx. Only server errors were checked before, so every
        // business-level rejection Meta returns with a 400 — an expired token,
        // a number that is not on WhatsApp, a closed window, a template
        // violation — was read as success and the message silently vanished.
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
