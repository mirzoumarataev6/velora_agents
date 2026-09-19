<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\HandleIncomingMessageJob;
use App\Models\Channel;
use App\Services\Channels\ChannelDriverManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GreenApiWebhookController extends Controller
{
    // Подтверждает вебхук Green API для указанного канала
    public function verify(Request $request, Channel $channel, ChannelDriverManager $drivers): Response
    {
        $this->assertGreenApi($channel);

        return $drivers->for($channel)->verifyWebhook($request, $channel) ?? response('OK', 200);
    }

    // Принимает вебхук Green API и ставит в очередь обработку входящих сообщений
    public function handle(Request $request, Channel $channel, ChannelDriverManager $drivers): Response
    {
        $this->assertGreenApi($channel);

        $driver = $drivers->for($channel);

        if (! $driver->authenticateIncomingRequest($request, $channel)) {
            abort(403);
        }

        foreach ($driver->splitDeliveries($request->all()) as $delivery) {
            HandleIncomingMessageJob::dispatch($channel, $delivery);
        }

        return response('OK', 200);
    }

    protected function assertGreenApi(Channel $channel): void
    {
        if ($channel->type !== 'greenapi') {
            abort(404);
        }
    }
}
