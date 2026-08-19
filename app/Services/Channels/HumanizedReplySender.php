<?php

namespace App\Services\Channels;

use App\Models\Agent;
use App\Models\Channel;
use Illuminate\Support\Sleep;

/**
 * Delivers one assistant reply to a customer, optionally as the 2-3 short
 * consecutive messages a person would actually type instead of a single wall
 * of text — with a typing indicator during each pause.
 *
 * This is a DELIVERY concern only. The reply is still one assistant turn: one
 * Message row, one lead-capture pass, one usage log. Nothing downstream sees
 * the split, so conversation history and analytics can't double-count it.
 */
class HumanizedReplySender
{
    public function __construct(protected HumanReplySplitter $splitter) {}

    /**
     * @return int How many messages the reply was delivered as.
     */
    public function send(
        ChannelDriverInterface $driver,
        Channel $channel,
        string $externalUserId,
        string $text,
        ?Agent $agent = null,
    ): int {
        $messages = $this->messagesFor($text, $agent);

        foreach ($messages as $index => $message) {
            if ($index > 0) {
                // Indicator first, then the pause, so the customer sees
                // "typing…" for the whole gap rather than after it.
                $driver->sendTypingIndicator($channel, $externalUserId);
                Sleep::for($this->delayMsFor($message))->milliseconds();
            }

            $driver->sendMessage($channel, $externalUserId, $message);
        }

        return count($messages);
    }

    /**
     * @return string[]
     */
    public function messagesFor(string $text, ?Agent $agent = null): array
    {
        if (! ($agent->humanize_replies ?? true)) {
            return [$text];
        }

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
