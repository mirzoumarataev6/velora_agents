<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\HandleIncomingMessageJob;
use App\Models\Channel;
use App\Services\Channels\ChannelDriverManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TelegramWebhookController extends Controller
{
    public function handle(Request $request, Channel $channel, ChannelDriverManager $drivers): Response
    {
        if ($channel->type !== 'telegram') {
            abort(404);
        }

        $driver = $drivers->for($channel);

        if (! $driver->authenticateIncomingRequest($request, $channel)) {
            abort(403);
        }

        // All real processing (parsing, RAG, sending the reply) happens in the
        // queued job — this handler only validates and enqueues, so Telegram
        // gets its 200 OK immediately regardless of downstream latency.
        HandleIncomingMessageJob::dispatch($channel, $request->all());

        return response('OK', 200);
    }
}
