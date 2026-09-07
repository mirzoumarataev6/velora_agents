<?php

namespace App\Services\Channels;

use App\Models\Channel;

/**
 * The graceful skip, for platforms that cannot carry a per-reply 👍/👎 control.
 *
 * See ChannelDriverInterface::supportsMessageFeedback() for what the bar is and
 * why WhatsApp and Instagram do not clear it. The point of putting the skip in
 * one place is that it is a REAL send: the customer still gets their answer,
 * they just get it without buttons. Nothing upstream has to know which channel
 * it is talking to, and no channel fakes a control it cannot honour.
 */
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

    public function confirmFeedback(Channel $channel, MessageFeedback $feedback, string $confirmation): void
    {
        // Nothing to confirm: this platform never showed a control.
    }
}
