<?php

namespace App\Services\Channels;

use App\Models\Channel;

trait HasNoMessageFeedback
{
    // Сообщает, что канал не поддерживает обратную связь по сообщениям
    public function supportsMessageFeedback(): bool
    {
        return false;
    }

    // Отправляет обычное сообщение, так как обратная связь каналом не поддерживается
    public function sendMessageWithFeedback(Channel $channel, string $externalUserId, string $text, int $messageId): void
    {
        $this->sendMessage($channel, $externalUserId, $text);
    }

    // Возвращает пустую обратную связь, так как канал её не поддерживает
    public function parseFeedback(array $payload, Channel $channel): ?MessageFeedback
    {
        return null;
    }

    // Подтверждает обратную связь — ничего не делает, так как канал её не поддерживает
    public function confirmFeedback(Channel $channel, MessageFeedback $feedback, string $confirmation): void {}
}
