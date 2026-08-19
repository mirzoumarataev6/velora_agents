<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\Channels\ChannelDriverManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes a freshly captured lead to the business owner's linked Telegram chat.
 *
 * The in-app bell is driven by the lead row itself (an unseen lead bumps the
 * badge), so this job is purely the optional Telegram fan-out: it no-ops
 * quietly when the owner hasn't linked a chat or the agent has no connected
 * Telegram bot to send through.
 */
class SendLeadNotificationJob implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 2;

    public function __construct(public Lead $lead) {}

    public function handle(ChannelDriverManager $drivers): void
    {
        $agent = $this->lead->agent;

        if (! $agent || ! $agent->notifiesOwnerViaTelegram()) {
            return;
        }

        // The owner can mute lead alerts account-wide from Settings without
        // having to unlink every agent's chat one by one.
        if ($agent->user && ! $agent->user->lead_notifications_enabled) {
            return;
        }

        // We reuse the agent's own customer-facing Telegram bot to deliver the
        // notification — the owner "started" it during linking, so it may DM them.
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
                $this->message($agent->name)
            );
        } catch (Throwable $e) {
            Log::warning('Failed to deliver lead notification to owner', [
                'lead_id' => $this->lead->id,
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function message(string $agentName): string
    {
        $lines = ["🔔 Новая заявка · {$agentName}"];

        if ($this->lead->customer_name) {
            $lines[] = "👤 {$this->lead->customer_name}";
        }
        if ($this->lead->contact) {
            $lines[] = "📞 {$this->lead->contact}";
        }
        if ($this->lead->items) {
            $lines[] = "🛒 {$this->lead->items}";
        }
        if ($this->lead->note) {
            $lines[] = "📝 {$this->lead->note}";
        }

        $lines[] = '';
        $lines[] = route('leads.index');

        return implode("\n", $lines);
    }
}
