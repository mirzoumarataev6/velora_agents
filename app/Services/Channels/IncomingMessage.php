<?php

namespace App\Services\Channels;

final class IncomingMessage
{
    public function __construct(
        public readonly string $externalMessageId,
        public readonly string $externalUserId,
        public readonly ?string $externalUserName,
        public readonly string $text,
        public readonly string $type = 'text',
        public readonly ?int $voiceDuration = null,
    ) {}

    public function isVoice(): bool
    {
        return str_starts_with($this->type, 'voice');
    }
}
