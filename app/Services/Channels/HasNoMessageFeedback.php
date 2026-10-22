<?php

namespace App\Services\Channels;

use App\Models\Channel;

trait HasNoMessageFeedback
{
    public function supportsMessageFeedback(): bool
    {
        return false;
    }

    public function sendMessageWithFeedback(Channel $channel, string $externalUserId, string $text, int $messageId): void
    {
        $this->sendMessage($channel, $externalUserId, $text);
    }

    public function parseFeedback(array $payload, Channel $channel): ?MessageFeedback
    {
        return null;
    }

    public function confirmFeedback(Channel $channel, MessageFeedback $feedback, string $confirmation): void {}
}
