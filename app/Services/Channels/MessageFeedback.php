<?php

namespace App\Services\Channels;

/**
 * A customer tapping 👍 or 👎 on one agent reply, normalised out of whatever
 * shape the platform delivered it in.
 *
 * `messageId` is OUR Message id, carried through the platform in the callback
 * payload and handed straight back to us. It is not trusted on the strength of
 * that round trip alone — the handler still checks the row belongs to this
 * channel and to the person who tapped.
 */
final class MessageFeedback
{
    public function __construct(
        /** The `messages.id` this vote is about, as claimed by the callback. */
        public readonly int $messageId,
        /** One of Message::RATINGS. */
        public readonly string $rating,
        /** Who tapped, in platform terms. Checked against the conversation. */
        public readonly string $externalUserId,
        /**
         * The platform's own id for the message carrying the buttons, so the
         * driver can edit it once the vote lands.
         */
        public readonly ?string $platformMessageId = null,
        /**
         * Platform handle for the tap itself. Telegram requires every
         * callback_query be acknowledged, or the client spins.
         */
        public readonly ?string $callbackId = null,
    ) {}
}
