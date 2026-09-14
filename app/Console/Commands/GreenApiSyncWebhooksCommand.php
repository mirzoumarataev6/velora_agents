<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\Channels\ChannelDriverManager;
use App\Services\Channels\GreenApiDriver;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Re-point every connected Green API instance's webhook at this app, via
 * setSettings. The Green API counterpart to telegram:sync-webhooks — for after
 * APP_URL changes, or when connect() could not register the webhook itself.
 *
 * The stored webhook token is reused, so an instance that already has it keeps
 * working throughout.
 */
class GreenApiSyncWebhooksCommand extends Command
{
    protected $signature = 'greenapi:sync-webhooks {--channel= : Only this channel id}';

    protected $description = 'Set the webhook URL on every connected Green API instance';

    public function handle(ChannelDriverManager $drivers): int
    {
        $channels = Channel::query()
            ->where('type', 'greenapi')
            ->where('status', 'connected')
            ->when($this->option('channel'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        if ($channels->isEmpty()) {
            $this->warn('No connected Green API channels.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($channels as $channel) {
            $driver = $drivers->for($channel);

            if (! $driver instanceof GreenApiDriver) {
                continue;
            }

            $label = "#{$channel->id} instance ".($channel->credentials['id_instance'] ?? '?');
            $token = $channel->credentials['webhook_token'] ?? Str::random(48);
            $result = $driver->registerWebhook($channel, $token);

            $channel->credentials = array_merge($channel->credentials ?? [], ['webhook_token' => $token]);
            $channel->settings = array_merge($channel->settings ?? [], [
                'webhook_url' => route('webhooks.greenapi', $channel),
                'webhook_registered' => $result['webhook_registered'],
                'webhook_error' => $result['webhook_error'],
            ]);
            $channel->save();

            if ($result['webhook_registered']) {
                $this->info("{$label}: webhook set to ".route('webhooks.greenapi', $channel));
            } else {
                $failed = true;
                $this->error("{$label}: {$result['webhook_error']}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
