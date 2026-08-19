<?php

namespace App\Services\Channels;

/**
 * Normalized shape a ChannelDriver parses an incoming webhook payload into,
 * independent of the source platform's wire format.
 */
final class IncomingMessage
{
    public function __construct(
        public readonly string $externalMessageId,
        public readonly string $externalUserId,
        public readonly ?string $externalUserName,
        public readonly string $text,
        public readonly string $type = 'text',
        /** Seconds, for voice turns only — shown beside the transcript in the inbox. */
        public readonly ?int $voiceDuration = null,
    ) {}

    /**
     * Voice-originated turns, whether or not the transcription succeeded. The
     * inbox marks all of them with a microphone: the customer spoke either way,
     * and an operator reading back needs to know that even when the text is a
     * "could not transcribe" placeholder.
     */
    public function isVoice(): bool
    {
        return str_starts_with($this->type, 'voice');
    }
}
