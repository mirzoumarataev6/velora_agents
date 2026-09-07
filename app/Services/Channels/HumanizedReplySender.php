<?php

namespace App\Services\Channels;

use App\Models\Channel;
use App\Models\Message;
use Illuminate\Support\Sleep;

/**
 * Delivers one assistant reply to a customer as the 2-3 short consecutive
 * messages a person would actually type instead of a single wall of text —
 * with a typing indicator during each pause.
 *
 * This is a DELIVERY concern only. The reply is still one assistant turn: one
 * Message row, one lead-capture pass, one usage log. Nothing downstream sees
 * the split, so conversation history and analytics can't double-count it.
 */
class HumanizedReplySender
{
    public function __construct(protected HumanReplySplitter $splitter) {}

    /**
     * @param  Message|null  $rateable  The assistant turn these messages are a
     *                                  delivery of. When given, the LAST message
     *                                  carries a 👍/👎 control bound to it — the
     *                                  last, because the answer is not finished
     *                                  until it lands, and asking someone to rate
     *                                  a reply still being typed is asking them
     *                                  to rate half of it. Channels that cannot
     *                                  carry a control simply send the text.
     * @return int How many messages the reply was delivered as.
     */
    public function send(
        ChannelDriverInterface $driver,
        Channel $channel,
        string $externalUserId,
        string $text,
        ?Message $rateable = null,
    ): int {
        $messages = $this->messagesFor($text);
        $last = count($messages) - 1;

        foreach ($messages as $index => $message) {
            if ($index > 0) {
                // Indicator first, then the pause, so the customer sees
                // "typing…" for the whole gap rather than after it.
                $driver->sendTypingIndicator($channel, $externalUserId);
                Sleep::for($this->delayMsFor($message))->milliseconds();
            }

            if ($index === $last && $rateable !== null) {
                $driver->sendMessageWithFeedback($channel, $externalUserId, $message, $rateable->id);

                continue;
            }

            $driver->sendMessage($channel, $externalUserId, $message);
        }

        return count($messages);
    }

    /**
     * @return string[]
     */
    public function messagesFor(string $text): array
    {
        // Unconditional. This used to be a per-agent toggle; sounding like a
        // person is not a preference an owner benefits from switching off, and
        // the splitter already leaves anything short as one message.
        return $this->splitter->split(
            $text,
            (int) config('agent.humanize.min_length', 200),
            (int) config('agent.humanize.max_messages', 3),
        );
    }

    /**
     * Pause before a follow-up message, scaled by its length (a longer message
     * reads as taking longer to type) and clamped to the configured range.
     */
    protected function delayMsFor(string $message): int
    {
        $min = (int) config('agent.humanize.min_delay_ms', 800);
        $max = (int) config('agent.humanize.max_delay_ms', 1500);
        $perChar = (float) config('agent.humanize.delay_ms_per_char', 6);

        return (int) max($min, min($max, $min + round(mb_strlen($message) * $perChar)));
    }
}
