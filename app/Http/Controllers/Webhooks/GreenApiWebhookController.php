<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\HandleIncomingMessageJob;
use App\Models\Channel;
use App\Services\Channels\ChannelDriverManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class GreenApiWebhookController extends Controller
{
    public function verify(Request $request, Channel $channel, ChannelDriverManager $drivers): Response
    {
        $this->assertGreenApi($channel);

        return $drivers->for($channel)->verifyWebhook($request, $channel) ?? response('OK', 200);
    }

    public function handle(Request $request, Channel $channel, ChannelDriverManager $drivers): Response
    {
        $this->assertGreenApi($channel);
        $this->normalizeJsonBody($request);

        $driver = $drivers->for($channel);

        if (! $driver->authenticateIncomingRequest($request, $channel)) {
            Log::warning('Green API webhook rejected: authentication failed', [
                'channel_id' => $channel->id,
                'type_webhook' => $request->input('typeWebhook'),
                'received_id_instance' => $request->input('instanceData.idInstance'),
                'has_authorization_header' => $request->hasHeader('Authorization'),
            ]);

            abort(403);
        }

        $payload = $request->all();
        $deliveries = $driver->splitDeliveries($payload);

        if ($deliveries === []) {
            Log::info('Green API webhook acknowledged without dispatching a job', [
                'channel_id' => $channel->id,
                ...$this->rawFields($payload),
            ]);
        }

        foreach ($deliveries as $delivery) {
            Log::info('Green API webhook dispatched for processing', [
                'channel_id' => $channel->id,
                ...$this->rawFields($delivery),
            ]);

            HandleIncomingMessageJob::dispatch($channel, $delivery);
        }

        return response('OK', 200);
    }

    protected function normalizeJsonBody(Request $request): void
    {
        if ($request->all() !== [] || $request->getContent() === '') {
            return;
        }

        $decoded = json_decode($request->getContent(), true);

        if (is_array($decoded)) {
            $request->replace($decoded);
        }
    }

    protected function rawFields(array $payload): array
    {
        $body = is_array($payload['body'] ?? null) ? $payload['body'] : $payload;

        return [
            'type_webhook' => $payload['typeWebhook'] ?? null,
            'type_message' => data_get($body, 'messageData.typeMessage'),
            'id_message' => $body['idMessage'] ?? null,
            'chat_id' => data_get($body, 'senderData.chatId'),
            'sender' => data_get($body, 'senderData.sender'),
            'from_me' => data_get($body, 'senderData.fromMe') ?? ($body['fromMe'] ?? null),
        ];
    }

    protected function assertGreenApi(Channel $channel): void
    {
        if ($channel->type !== 'greenapi') {
            Log::warning('Green API webhook rejected: channel is not a Green API channel', [
                'channel_id' => $channel->id,
                'channel_type' => $channel->type,
            ]);

            abort(404);
        }
    }
}
