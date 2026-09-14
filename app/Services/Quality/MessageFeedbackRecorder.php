<?php

namespace App\Services\Quality;

use App\Models\Channel;
use App\Models\Message;
use App\Services\Channels\ChannelDriverInterface;
use App\Services\Channels\MessageFeedback;
use App\Services\Reply\CustomerLocale;
use Illuminate\Support\Facades\Log;

/**
 * Turns a customer's 👍/👎 tap into a rating on the reply it was about.
 *
 * The whole class is one guarded write and one confirmation, but both halves
 * have a sharp edge:
 *
 *  - the message id arrives from OUTSIDE. It made a round trip through
 *    Telegram inside a callback_data string, and while only Telegram can
 *    reach the webhook (the secret-token header sees to that), the id itself
 *    is just a number in a payload. So the row is re-derived under the
 *    constraints that actually matter — it must be an assistant reply, on this
 *    channel, in the conversation belonging to the person who tapped — rather
 *    than fetched by primary key and trusted.
 *
 *  - the customer WILL tap twice. Buttons invite it, and Telegram redelivers
 *    a callback it thinks went unacknowledged. Message::rate() makes exactly
 *    one of those the vote; this decides what the other one is told.
 */
class MessageFeedbackRecorder
{
    public function __construct(protected CustomerLocale $locales) {}

    /**
     * @return bool Whether the vote was recorded by THIS call. False covers
     *              both "already rated" and "no such message" — the caller
     *              stops either way, and the customer has been answered.
     */
    public function record(ChannelDriverInterface $driver, Channel $channel, MessageFeedback $feedback): bool
    {
        $message = $this->resolve($channel, $feedback);

        if ($message === null) {
            // A stale button on a conversation that has since been deleted, or
            // an id that never belonged to this customer. Nothing to record —
            // but the tap is still acknowledged, or their client spins.
            Log::info('Discarded a message rating that matched no reply of this customer', [
                'channel_id' => $channel->id,
                'claimed_message_id' => $feedback->messageId,
            ]);

            $driver->confirmFeedback($channel, $feedback, $this->say($channel, $feedback, 'expired'));

            return false;
        }

        $recorded = $message->rate($feedback->rating);

        $driver->confirmFeedback(
            $channel,
            $feedback,
            $this->say($channel, $feedback, $recorded ? 'thanks' : 'already'),
        );

        return $recorded;
    }

    /**
     * The rated reply, re-derived rather than trusted. See the class docblock.
     */
    protected function resolve(Channel $channel, MessageFeedback $feedback): ?Message
    {
        return Message::query()
            ->whereKey($feedback->messageId)
            ->where('role', 'assistant')
            ->whereHas('conversation', fn ($query) => $query
                ->where('channel_id', $channel->id)
                ->where('external_user_id', $feedback->externalUserId))
            ->first();
    }

    /**
     * The confirmation, in the language this customer has been writing in.
     *
     * Uses the same detector the reply pipeline uses, on the same recent
     * messages, so a customer who has had the whole conversation in Tajik is
     * not thanked in Russian on the last step of it.
     */
    protected function say(Channel $channel, MessageFeedback $feedback, string $key): string
    {
        $conversation = $channel->conversations()
            ->where('external_user_id', $feedback->externalUserId)
            ->first();

        $locale = $conversation === null
            ? config('app.locale')
            : $this->locales->forCustomer($conversation);

        return __("feedback.confirm.{$key}", [], $locale);
    }
}
