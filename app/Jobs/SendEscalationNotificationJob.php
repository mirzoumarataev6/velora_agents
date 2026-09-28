<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\Channels\ChannelDriverManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

use function __;

class SendEscalationNotificationJob implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 2;

    // Создаёт задачу уведомления владельца об эскалации диалога
    public function __construct(public Conversation $conversation) {}

    // Отправляет владельцу в Telegram уведомление о диалоге, требующем внимания человека
    public function handle(ChannelDriverManager $drivers): void
    {
        $agent = $this->conversation->agent;

        if (! $agent || ! $agent->notifiesOwnerViaTelegram()) {
            return;
        }

        if ($agent->user && ! $agent->user->lead_notifications_enabled) {
            return;
        }

        $channel = $agent->channels()
            ->where('type', 'telegram')
            ->where('status', 'connected')
            ->first();

        if (! $channel) {
            return;
        }

        try {
            $drivers->for($channel)->sendMessage(
                $channel,
                $agent->notify_telegram_chat_id,
                $this->message($agent->name, $agent->user?->locale)
            );
        } catch (Throwable $e) {
            Log::warning('Failed to deliver escalation notification to owner', [
                'conversation_id' => $this->conversation->id,
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // Формирует текст уведомления об эскалации для отправки владельцу
    protected function message(string $agentName, ?string $locale): string
    {
        $locale = $locale ?: config('app.locale');

        $customer = $this->conversation->external_user_name
            ?: ($this->conversation->external_user_id ?? '—');

        $signalKey = 'conversations.escalation.signals.'.($this->conversation->needs_human_signal ?? 'frustration');

        $lines = [
            '⚠️ '.__('conversations.escalation.notify.title', [], $locale)." · {$agentName}",
            '👤 '.$customer,
            '· '.__($signalKey, [], $locale),
        ];

        if ($this->conversation->needs_human_summary) {
            $lines[] = '💬 '.$this->conversation->needs_human_summary;
        }

        $lines[] = '';
        $lines[] = __('conversations.escalation.notify.cta', [], $locale);
        $lines[] = route('conversations.index', ['status' => 'needs_human']);

        return implode("\n", $lines);
    }
}
