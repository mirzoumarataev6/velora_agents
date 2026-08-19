<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\Channels\ChannelDriverManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

use function __;

/**
 * Tells the owner, on Telegram, that a customer has been escalated.
 *
 * Deliberately the same shape as SendLeadNotificationJob: the in-app bell is
 * driven by the conversation row itself (an unread escalation bumps the badge),
 * so this is purely the optional Telegram fan-out and no-ops quietly when the
 * owner hasn't linked a chat.
 */
class SendEscalationNotificationJob implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 2;

    public function __construct(public Conversation $conversation) {}

    public function handle(ChannelDriverManager $drivers): void
    {
        $agent = $this->conversation->agent;

        if (! $agent || ! $agent->notifiesOwnerViaTelegram()) {
            return;
        }

        // The same account-wide mute that silences lead alerts: an owner who
        // turned notifications off meant all of them.
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
