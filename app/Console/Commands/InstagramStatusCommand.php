<?php

namespace App\Console\Commands;

use App\Models\Channel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class InstagramStatusCommand extends Command
{
    protected $signature = 'instagram:status';

    protected $description = 'Check whether the Instagram (instagrapi) microservice is running, and the login state of each connected account';

    public function handle(): int
    {
        $baseUrl = rtrim((string) config('agent.instagram_service.url'), '/');

        try {
            $health = Http::timeout(5)->get("{$baseUrl}/health");
        } catch (Throwable $e) {
            $this->error("Could not reach the Instagram service at {$baseUrl}: {$e->getMessage()}");
            $this->line('Is `composer run dev` running instagram_service on port 8001?');

            return self::FAILURE;
        }

        if ($health->failed()) {
            $this->error("Instagram service returned HTTP {$health->status()}.");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Instagram service is running (%d/%d accounts logged in).',
            $health->json('accounts_connected'),
            $health->json('accounts_total'),
        ));

        $channels = Channel::query()->where('type', 'instagrapi')->get();

        if ($channels->isEmpty()) {
            $this->line('No Instagram (instagrapi) channels configured.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($channels as $channel) {
            $label = "#{$channel->id} (agent #{$channel->agent_id})";

            if ($channel->status !== 'connected') {
                $this->line("{$label}: {$channel->status} in Velora — skipped.");

                continue;
            }

            try {
                $status = Http::timeout(5)->get("{$baseUrl}/status/{$channel->uuid}");
            } catch (Throwable $e) {
                $this->error("{$label}: could not reach service ({$e->getMessage()})");
                $failed = true;

                continue;
            }

            if ($status->failed()) {
                $this->error("{$label}: HTTP {$status->status()}");
                $failed = true;

                continue;
            }

            if ($status->json('challenged')) {
                $this->warn("{$label}: challenged — needs verification in the Instagram app before it can send or receive.");
                $failed = true;
            } elseif ($status->json('logged_in')) {
                $this->info("{$label}: logged in as ".$status->json('username'));
            } else {
                $this->warn("{$label}: not logged in (service has no session for this channel — reconnect it from the Channels tab).");
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
