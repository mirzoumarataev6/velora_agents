<?php

namespace App\Services\Channels;

use App\Models\Channel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface ChannelDriverInterface
{
    public function verifyWebhook(Request $request, Channel $channel): ?Response;

    public function authenticateIncomingRequest(Request $request, Channel $channel): bool;

    public function splitDeliveries(array $payload): array;

    public function parseIncomingMessage(array $payload, Channel $channel): ?IncomingMessage;

    public function sendMessage(Channel $channel, string $externalUserId, string $text): void;

    public function sendImage(Channel $channel, string $externalUserId, string $url, string $caption = ''): void;

    public function supportsMessageFeedback(): bool;

    public function sendMessageWithFeedback(Channel $channel, string $externalUserId, string $text, int $messageId): void;

    public function parseFeedback(array $payload, Channel $channel): ?MessageFeedback;

    public function confirmFeedback(Channel $channel, MessageFeedback $feedback, string $confirmation): void;

    public function sendTypingIndicator(Channel $channel, string $externalUserId): void;

    public function connect(Channel $channel): array;

    public function disconnect(Channel $channel): void;
}
