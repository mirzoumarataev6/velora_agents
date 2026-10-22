<?php

namespace App\Services\Channels;

final class MessageFeedback
{
    public function __construct(
        public readonly int $messageId,
        public readonly string $rating,
        public readonly string $externalUserId,
        public readonly ?string $platformMessageId = null,
        public readonly ?string $callbackId = null,
    ) {}
}
