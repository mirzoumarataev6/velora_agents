<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\HandleIncomingMessageJob;
use App\Models\Channel;
use App\Services\Channels\ChannelDriverManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared webhook entry point for both Meta channel types (WhatsApp, Instagram)
 * — they use the identical GET verification handshake and POST envelope
 * shape, differing only in driver-specific payload parsing.
 */
class MetaWebhookController extends Controller
{
    public function verify(Request $request, Channel $channel, ChannelDriverManager $drivers, string $type): Response
    {
        $this->assertChannelType($channel, $type);

        $driver = $drivers->for($channel);

        return $driver->verifyWebhook($request, $channel) ?? response('Not Found', 404);
    }

    public function handle(Request $request, Channel $channel, ChannelDriverManager $drivers, string $type): Response
    {
        $this->assertChannelType($channel, $type);

        $driver = $drivers->for($channel);

        if (! $driver->authenticateIncomingRequest($request, $channel)) {
            abort(403);
        }

        // Meta retries aggressively on slow/non-200 responses — all real
        // processing (parsing, RAG, sending the reply) happens in the queued
        // job so this handler can return 200 immediately.
        //
        // ONE JOB PER MESSAGE, not per delivery. Meta batches: entry, changes
        // and messages are all arrays, and several messages arriving close
        // together are posted together. The drivers can only parse one message
        // per payload, so a batched delivery used to be answered once and the
        // rest thrown away.
        foreach ($driver->splitDeliveries($request->all()) as $delivery) {
            HandleIncomingMessageJob::dispatch($channel, $delivery);
        }

        return response('OK', 200);
    }

    protected function assertChannelType(Channel $channel, string $type): void
    {
        if ($channel->type !== $type) {
            abort(404);
        }
    }
}
