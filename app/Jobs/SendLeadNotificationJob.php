<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Haggling\HagglingService;
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
        if ($this->lead->isHaggle()) {
            return $this->priceRequestMessage($agentName);
        }

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

    /**
     * A price the customer asked for that the assistant was not allowed to give.
     *
     * Shaped differently from an order on purpose: this one is a DECISION the
     * owner has to make, and a customer is waiting on it in a live chat. The
     * numbers come first, and the link goes straight to the pending list rather
     * than to the whole inbox.
     */
    protected function priceRequestMessage(string $agentName): string
    {
        $lines = ["💬 Запрос цены · {$agentName}"];

        if ($this->lead->customer_name) {
            $lines[] = "👤 {$this->lead->customer_name}";
        }
        if ($this->lead->phone) {
            $lines[] = "📞 {$this->lead->phone}";
        }
        if ($this->lead->items) {
            $lines[] = "🛒 {$this->lead->items}";
        }

        $requested = Lead::formatPrice($this->lead->requested_price);
        $listed = Lead::formatPrice($this->lead->listed_price);

        if ($requested !== null) {
            $discount = $this->lead->requestedDiscountPercent();
            $lines[] = '💰 Просит: '.$requested
                .($listed !== null ? " (в каталоге {$listed}".($discount !== null ? ", −{$discount}%" : '').')' : '');
        }

        if ($this->lead->negotiation_summary) {
            $lines[] = "📝 {$this->lead->negotiation_summary}";
        }

        $lines[] = '';
        $lines[] = 'Клиент ждёт ответа — согласуйте или откажите:';
        $lines[] = route('leads.index', ['status' => HagglingService::STATUS_PENDING]);

        return implode("\n", $lines);
    }
}
