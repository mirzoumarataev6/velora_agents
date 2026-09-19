<?php

namespace App\Services\Quality;

use App\Models\Channel;
use App\Models\Message;
use App\Services\Channels\ChannelDriverInterface;
use App\Services\Channels\MessageFeedback;
use App\Services\Reply\CustomerLocale;
use Illuminate\Support\Facades\Log;

class MessageFeedbackRecorder
{
    // Создаёт регистратор обратной связи с сервисом определения локали клиента
    public function __construct(protected CustomerLocale $locales) {}

    // Сохраняет оценку сообщения и подтверждает получение обратной связи в канале
    public function record(ChannelDriverInterface $driver, Channel $channel, MessageFeedback $feedback): bool
    {
        $message = $this->resolve($channel, $feedback);

        if ($message === null) {
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
