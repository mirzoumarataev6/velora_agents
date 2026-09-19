<?php

namespace App\Jobs;

use App\Models\Lead;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Haggling\HagglingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendLeadNotificationJob implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public int $tries = 2;

    // Создаёт задачу уведомления владельца о новом лиде
    public function __construct(public Lead $lead) {}

    // Отправляет владельцу в Telegram уведомление о новом лиде или запросе цены
    public function handle(ChannelDriverManager $drivers): void
    {
        $agent = $this->lead->agent;

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
