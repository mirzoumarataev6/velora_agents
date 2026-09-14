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

/**
 * Instagram DMs through instagrapi (github.com/subzeroid/instagrapi) — an
 * unofficial private-API client driving an ordinary personal/business login,
 * as opposed to InstagramDriver's official Graph API + Meta webhooks.
 *
 * There is no PHP-side webhook for this channel. instagrapi cannot receive
 * push notifications, so a small Python microservice (instagram_service/)
 * logs in, polls Instagram for new DMs, and POSTs each one to this app's
 * POST /internal/instagram/incoming — see InstagramIncomingController. This
 * driver's parseIncomingMessage() reads that same normalized shape; the
 * verify/authenticate methods below are near no-ops because nothing external
 * ever calls them directly for this channel.
 *
 * No 24-hour customer-service window: that is a Graph API rule, and does not
 * apply to a private-API session acting as an ordinary account. Sending too
 * fast or to strangers is still how such accounts get flagged — see the UI
 * warning on the Channels tab.
 */
class InstagrapiDriver implements ChannelDriverInterface
{
    use HasNoMessageFeedback, SplitsLongMessages;

    // Instagram's documented DM limit is 1000 characters; kept conservative
    // rather than exact since instagrapi does not expose the real cutoff.
    protected const MAX_MESSAGE_LENGTH = 1000;

    public function __construct(protected OutboundMarkdown $markdown = new OutboundMarkdown) {}

    public function verifyWebhook(Request $request, Channel $channel): ?Response
    {
        // No GET handshake — this channel has no public webhook route at all.
        return null;
    }

    public function authenticateIncomingRequest(Request $request, Channel $channel): bool
    {
        // Incoming messages never reach a driver method through an HTTP
        // request for this channel: they arrive at
        // InstagramIncomingController, which is restricted to localhost by
        // RestrictToLocalhost before HandleIncomingMessageJob is ever
        // dispatched. Nothing left to check by the time this would run.
        return true;
    }

    /**
     * One message per delivery from the Python service — nothing to split.
     */
    public function splitDeliveries(array $payload): array
    {
        return [$payload];
    }

    /**
     * Parses the normalized shape InstagramIncomingController hands the job:
     * {from_user_id, from_username, text, message_id}.
     */
    public function parseIncomingMessage(array $payload, Channel $channel): ?IncomingMessage
    {
        $userId = (string) ($payload['from_user_id'] ?? '');
        $messageId = (string) ($payload['message_id'] ?? '');

        if ($userId === '' || $messageId === '') {
            return null;
        }

        $text = trim((string) ($payload['text'] ?? ''));
        $userName = $payload['from_username'] ?? null;

        // Media DMs (photo/video/reel share) are not forwarded by the Python
        // side at all — only text messages are — so an empty text here means
        // something upstream sent a malformed payload rather than a real type
        // to branch on.
        if ($text === '') {
            return null;
        }

        return new IncomingMessage($messageId, $userId, $userName, $text, 'text');
    }

    public function sendMessage(Channel $channel, string $externalUserId, string $text): void
    {
        // Plain text: Instagram DM has no bold/italic markup to target, same
        // treatment as InstagramDriver::toPlainText().
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

    public function sendTypingIndicator(Channel $channel, string $externalUserId): void
    {
        // instagrapi can send a typing indicator, but the Python service does
        // not expose one yet. Best-effort no-op, same as the other drivers.
    }

    /** Not part of ChannelDriverInterface — nothing downstream branches on
     * voice support per-driver today, but this channel has no voice handling
     * at all (unlike Telegram/WhatsApp/GreenApi), so the flag exists for
     * whatever reads it next.
     */
    public function supportsVoiceMessages(): bool
    {
        return false;
    }

    /**
     * Pushes this channel's credentials to the Python service (POST /connect),
     * keyed by the channel's own uuid — the service is multi-tenant and holds
     * no credentials of its own; every account it knows about arrived through
     * this call, one owner's login at a time. Nothing is registered on
     * Instagram's side the way a webhook URL is for the other channels — the
     * Python service's own polling loop is what "connects" this channel.
     *
     * @throws RuntimeException when the service is not running, or login
     *                          failed (bad credentials or a challenge).
     */
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
        // Tells the Python service to forget this channel's session (logout +
        // delete instagram_sessions/{uuid}.json) — it is per-channel state, so
        // there is something real to tear down here, unlike the Meta drivers.
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

    /**
     * @param  array<string, mixed>  $payload
     */
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
