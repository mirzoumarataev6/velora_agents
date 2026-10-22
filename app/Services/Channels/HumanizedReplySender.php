<?php

namespace App\Services\Channels;

use App\Models\Channel;
use App\Models\Message;
use Illuminate\Support\Sleep;

class HumanizedReplySender
{
    public function __construct(protected HumanReplySplitter $splitter) {}

    public function send(
        ChannelDriverInterface $driver,
        Channel $channel,
        string $externalUserId,
        string $text,
        ?Message $rateable = null,
        ?string $feedbackPrompt = null,
    ): int {
        $messages = $this->messagesFor($text);
        $last = count($messages) - 1;

        foreach ($messages as $index => $message) {
            if ($index > 0) {
                $driver->sendTypingIndicator($channel, $externalUserId);
                Sleep::for($this->delayMsFor($message))->milliseconds();
            }

            if ($index === $last && $rateable !== null) {
                if ($feedbackPrompt !== null && $driver->supportsMessageFeedback()) {
                    $message .= "\n\n".$feedbackPrompt;
                }

                $driver->sendMessageWithFeedback($channel, $externalUserId, $message, $rateable->id);

                continue;
            }

            $driver->sendMessage($channel, $externalUserId, $message);
        }

        return count($messages);
    }

    public function messagesFor(string $text): array
    {
        return $this->splitter->split(
            $text,
            (int) config('agent.humanize.min_length', 200),
            (int) config('agent.humanize.max_messages', 3),
        );
    }

    protected function delayMsFor(string $message): int
    {
        $min = (int) config('agent.humanize.min_delay_ms', 800);
        $max = (int) config('agent.humanize.max_delay_ms', 1500);
        $perChar = (float) config('agent.humanize.delay_ms_per_char', 6);

        return (int) max($min, min($max, $min + round(mb_strlen($message) * $perChar)));
    }
}
