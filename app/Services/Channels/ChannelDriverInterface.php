<?php

namespace App\Services\Channels;

use App\Models\Channel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Contract every messaging channel integration (Telegram, WhatsApp, Instagram)
 * implements, so HandleIncomingMessageJob and the webhook controller stay
 * platform-agnostic.
 */
interface ChannelDriverInterface
{
    /**
     * Handle a GET-based webhook verification handshake (WhatsApp/Instagram
     * echo back hub.challenge). Return a Response to short-circuit the
     * request, or null if this platform has no GET verification step
     * (e.g. Telegram).
     */
    public function verifyWebhook(Request $request, Channel $channel): ?Response;

    /**
     * Authenticate an incoming POST webhook request (e.g. Telegram's secret
     * token header, Meta's X-Hub-Signature). Return false to reject.
     */
    public function authenticateIncomingRequest(Request $request, Channel $channel): bool;

    /**
     * Parse a webhook POST body into a normalized message, or null if the
     * payload isn't a message this driver handles (e.g. a delivery receipt).
     * Takes the decoded JSON body (not a Request) so this can run inside a
     * queued job without needing to (de)serialize an HTTP request.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseIncomingMessage(array $payload, Channel $channel): ?IncomingMessage;

    /**
     * Send a text reply to a customer. Implementations are responsible for
     * splitting text that exceeds the platform's message length limit.
     */
    public function sendMessage(Channel $channel, string $externalUserId, string $text): void;

    public function sendTypingIndicator(Channel $channel, string $externalUserId): void;

    /**
     * Register the webhook URL with the platform and verify the credentials
     * are valid. Returns platform account info (e.g. ['username' => '...'])
     * to store in the channel's settings.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException on invalid credentials or a failed API call.
     */
    public function connect(Channel $channel): array;

    /**
     * Unregister the webhook and disconnect.
     */
    public function disconnect(Channel $channel): void;
}
