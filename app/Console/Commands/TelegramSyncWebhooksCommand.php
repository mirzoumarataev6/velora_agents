<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\TelegramDriver;
use Illuminate\Console\Command;
use Throwable;

class TelegramSyncWebhooksCommand extends Command
{
    protected $signature = 'telegram:sync-webhooks {--channel= : Only this channel id}';

    protected $description = 'Re-register connected Telegram webhooks with the current allowed_updates';

    public function handle(ChannelDriverManager $drivers): int
    {
        $channels = Channel::query()
            ->where('type', 'telegram')
            ->where('status', 'connected')
            ->when($this->option('channel'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        if ($channels->isEmpty()) {
            $this->warn('No connected Telegram channels.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($channels as $channel) {
            $driver = $drivers->for($channel);

            if (! $driver instanceof TelegramDriver) {
                continue;
            }

            $label = "#{$channel->id} @".($channel->settings['bot_username'] ?? '?');

            try {
                $before = $driver->webhookInfo($channel)['allowed_updates'] ?? ['(all default)'];
                $driver->syncWebhook($channel);
                $after = $driver->webhookInfo($channel)['allowed_updates'] ?? ['(all default)'];

                $this->info("{$label}: [".implode(', ', $before).'] → ['.implode(', ', $after).']');
            } catch (Throwable $e) {
                $failed = true;
                $this->error("{$label}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
