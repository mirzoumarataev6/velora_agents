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
     * Split ONE webhook delivery into one payload per customer message it
     * carries.
     *
     * Exists because Meta batches. `entry`, `changes` and `messages` are all
     * arrays in the Cloud API envelope, and Meta will put several customers'
     * messages — or several from one customer — into a single POST when they
     * arrive close together. parseIncomingMessage() returns at most one
     * message, so reading only index [0] silently dropped the rest: the
     * customer's second question was never answered and nothing anywhere
     * recorded that it had arrived.
     *
     * Telegram sends exactly one update per delivery and returns the payload
     * unchanged.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>> One payload per message; empty if none.
     */
    public function splitDeliveries(array $payload): array;

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

    /**
     * Can this platform carry a per-reply 👍/👎 control?
     *
     * The bar is deliberately high, and it is not "can this platform draw
     * buttons". It is all three of:
     *
     *  - buttons can be attached to an ordinary text reply, without changing
     *    what the customer sees the reply as;
     *  - the tap comes back identified against THAT message, not as a new
     *    inbound message that has to be guessed at;
     *  - the message can be edited afterwards, so the buttons can be taken
     *    away and replaced with a confirmation.
     *
     * Today only Telegram clears it. WhatsApp and Instagram can both draw
     * buttons, but neither can edit a message that has been sent, and both
     * return a tap as a fresh inbound message — so a vote could not be tied to
     * a specific reply, and the control could never be retired once used.
     * Half-implementing it there would give the owner a rating column that
     * quietly means something different per channel, which is worse than not
     * offering it.
     *
     * A driver answering false must still be safe to call sendMessageWithFeedback
     * on: it sends the reply, without a control.
     */
    public function supportsMessageFeedback(): bool;

    /**
     * Send a reply with a 👍/👎 control bound to `$messageId` (a `messages.id`).
     *
     * Falls back to a plain send where the platform cannot carry one, so
     * callers never have to branch on the capability themselves.
     */
    public function sendMessageWithFeedback(Channel $channel, string $externalUserId, string $text, int $messageId): void;

    /**
     * Pull a 👍/👎 vote out of a webhook payload, or null if this delivery is
     * not one. Same contract as parseIncomingMessage: decoded JSON in, a
     * normalised value object or null out, no HTTP.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parseFeedback(array $payload, Channel $channel): ?MessageFeedback;

    /**
     * Close the loop in the customer's chat: acknowledge the tap, take the
     * buttons away so the reply cannot be voted on twice, and leave
     * `$confirmation` in their place.
     *
     * Best-effort by contract — a vote that is recorded but not confirmed is a
     * cosmetic failure, and must not cost the caller anything.
     */
    public function confirmFeedback(Channel $channel, MessageFeedback $feedback, string $confirmation): void;

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
