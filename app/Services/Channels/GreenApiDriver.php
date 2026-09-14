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

/**
 * WhatsApp through Green API (green-api.com) — an unofficial gateway driving a
 * linked WhatsApp Web session on the owner's own number, as opposed to
 * WhatsAppDriver's official Cloud API. Notification format:
 * https://green-api.com/en/docs/api/receiving/notifications-format/
 *
 * Where it differs from the Cloud API, and why the code looks the way it does:
 *  - Webhooks carry no signature. The body names the instance it came from,
 *    which must match the stored idInstance. idInstance alone is not a secret
 *    (it is in every API URL), so once connect() has registered the webhook
 *    itself, the per-channel token it handed Green API (webhookUrlToken) must
 *    also come back in the Authorization header.
 *  - No GET handshake: verifyWebhook() just answers 200.
 *  - The webhook URL CAN be set over the API (setSettings), so connect() does
 *    it — and reports, rather than fails, when it cannot.
 *  - No message editing and no inline buttons, so no 👍/👎 (HasNoMessageFeedback).
 */
class GreenApiDriver implements ChannelDriverInterface
{
    use ChecksMessagingWindow, DescribesImagesWithConfidence, HasNoMessageFeedback, SplitsLongMessages;

    public const DEFAULT_API_URL = 'https://7107.api.greenapi.com';

    // Green API accepts far more, but WhatsApp clients render long walls of
    // text badly; kept equal to the Cloud driver so both channels read alike.
    protected const MAX_MESSAGE_LENGTH = 4096;

    protected const INCOMING_MESSAGE = 'incomingMessageReceived';

    public function __construct(
        protected LlmProviderInterface $llm,
        protected VoiceTranscriber $transcriber,
        protected OutboundMarkdown $markdown = new OutboundMarkdown,
    ) {}

    public function verifyWebhook(Request $request, Channel $channel): ?Response
    {
        // Green API has no verification handshake; a GET is only ever someone
        // checking whether the endpoint is up.
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

        // Demanded only once Green API is known to have the token, i.e.
        // connect()'s setSettings call succeeded. An owner who pasted the URL
        // by hand never gave Green API a token, and requiring one would reject
        // every message they receive.
        if (empty($channel->settings['webhook_registered']) || empty($token)) {
            return true;
        }

        // Accepted with or without the "Bearer " prefix.
        return hash_equals($token, Str::after((string) $request->header('Authorization', ''), 'Bearer '));
    }

    /**
     * One notification per POST, but most are not customer messages — delivery
     * statuses, the owner's own outgoing messages, instance state changes.
     * Those are dropped here so they never cost a queued job.
     */
    public function splitDeliveries(array $payload): array
    {
        return ($payload['typeWebhook'] ?? null) === self::INCOMING_MESSAGE ? [$payload] : [];
    }

    public function parseIncomingMessage(array $payload, Channel $channel): ?IncomingMessage
    {
        if (($payload['typeWebhook'] ?? null) !== self::INCOMING_MESSAGE) {
            return null;
        }

        // The documented notification is flat; some examples wrap everything
        // below instanceData in "body". Both are read.
        $body = is_array($payload['body'] ?? null) ? $payload['body'] : $payload;

        $chatId = (string) data_get($body, 'senderData.chatId', '');
        $messageId = (string) ($body['idMessage'] ?? '');

        // Personal chats only: a group (@g.us) is not a customer talking to the
        // business, and answering in one would reply to everybody in it.
        if (! str_ends_with($chatId, '@c.us') || $messageId === '') {
            return null;
        }

        $userId = Str::before($chatId, '@');
        $userName = (string) (data_get($body, 'senderData.senderName') ?: data_get($body, 'senderData.chatName') ?: '') ?: null;
        $data = is_array($body['messageData'] ?? null) ? $body['messageData'] : [];
        $type = (string) ($data['typeMessage'] ?? '');

        return match ($type) {
            'textMessage' => new IncomingMessage($messageId, $userId, $userName, (string) data_get($data, 'textMessageData.textMessage', ''), 'text'),
            // A message with a link preview, or a reply quoting an earlier one.
            'extendedTextMessage', 'quotedMessage' => new IncomingMessage($messageId, $userId, $userName, (string) data_get($data, 'extendedTextMessageData.text', ''), 'text'),
            'imageMessage' => $this->buildPhotoMessage($messageId, $userId, $userName, $data['fileMessageData'] ?? []),
            'audioMessage' => $this->handleVoice($channel, $messageId, $userId, $userName, $data['fileMessageData'] ?? []),
            // Not the customer asking anything — same silence as WhatsAppDriver.
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

    /**
     * @param  array<string, mixed>  $file
     */
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

    /**
     * @param  array<string, mixed>  $file
     * @return array{confident: bool, description: ?string}
     */
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

    /**
     * Same contract as TelegramDriver::handleVoice(): the transcript becomes an
     * ordinary text turn, and every failure is a typed placeholder rather than
     * an exception. Green API reports neither duration nor size up front, so
     * the byte limit is enforced on the download itself.
     *
     * @param  array<string, mixed>  $file
     */
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

            // Extension on the name actually written — ffmpeg and Whisper both
            // go by it. tempnam() creates the file it reserves, so release it
            // (see TelegramDriver::downloadVoice()).
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
        // Green API itself would deliver after 24 hours — it is a WhatsApp Web
        // session, not the Cloud API. The window is kept so both WhatsApp
        // channels behave the same, and because unsolicited messages from an
        // unofficial client are the quickest way to get a number banned.
        $this->assertWithinMessagingWindow($channel, $externalUserId);

        // Same fallback as WhatsAppDriver: visible asterisks beat a lost reply.
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

            // Throws for the same reason WhatsAppDriver does: a swallowed
            // failure shows the operator a reply the customer never received.
            if ($response->failed()) {
                throw new RuntimeException("Green API rejected the message (HTTP {$response->status()}): {$this->errorOf($response)}");
            }
        }
    }

    /**
     * "79001234567" → "79001234567@c.us". An id that already names its chat
     * is passed through untouched.
     */
    public function chatId(string $externalUserId): string
    {
        return str_contains($externalUserId, '@')
            ? $externalUserId
            : preg_replace('/\D/', '', $externalUserId).'@c.us';
    }

    public function sendTypingIndicator(Channel $channel, string $externalUserId): void
    {
        // Best-effort no-op, as for the Cloud driver.
    }

    /**
     * Validates the credentials, then tries to point the instance's webhook at
     * us. A failed registration does not fail the connection: the owner can
     * still paste the URL into the Green API console, and the result says why.
     */
    public function connect(Channel $channel): array
    {
        $response = $this->call($channel, 'get', 'getStateInstance');

        if ($response->failed()) {
            throw new RuntimeException("Green API rejected the credentials (HTTP {$response->status()}) — check idInstance, apiTokenInstance and apiUrl.");
        }

        return [
            // "authorized" once the QR code has been scanned; anything else
            // means messages will not arrive yet.
            'state_instance' => $response->json('stateInstance'),
            'webhook_url' => route('webhooks.greenapi', $channel),
        ] + $this->registerWebhook($channel, Str::random(48));
    }

    /**
     * Set the webhook URL (and the token Green API must send back with it) via
     * setSettings — Green API's counterpart to Telegram's setWebhook.
     *
     * @return array{webhook_registered: bool, webhook_error: ?string, webhook_token: string}
     */
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
        // Stop Green API posting to a channel that no longer exists here.
        $this->call($channel, 'post', 'setSettings', ['webhookUrl' => '', 'webhookUrlToken' => '']);
    }

    /**
     * @param  'get'|'post'  $method
     * @param  array<string, mixed>  $payload
     */
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
            // Without the exception's message: it quotes the URL, which embeds the token.
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
