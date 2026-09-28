<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\HandleIncomingMessageJob;
use App\Models\Channel;
use App\Services\Channels\ChannelDriverManager;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MetaWebhookController extends Controller
{
    // Подтверждает вебхук Meta (WhatsApp/Instagram) для указанного канала
    public function verify(Request $request, Channel $channel, ChannelDriverManager $drivers, string $type): Response
    {
        $this->assertChannelType($channel, $type);

        $driver = $drivers->for($channel);

        return $driver->verifyWebhook($request, $channel) ?? response('Not Found', 404);
    }

    // Принимает вебхук Meta и ставит в очередь обработку входящих сообщений
    public function handle(Request $request, Channel $channel, ChannelDriverManager $drivers, string $type): Response
    {
        $this->assertChannelType($channel, $type);

        $driver = $drivers->for($channel);

        if (! $driver->authenticateIncomingRequest($request, $channel)) {
            abort(403);
        }

        foreach ($driver->splitDeliveries($request->all()) as $delivery) {
            HandleIncomingMessageJob::dispatch($channel, $delivery);
        }

        return response('OK', 200);
    }

    // Проверяет, что тип канала соответствует ожидаемому
    protected function assertChannelType(Channel $channel, string $type): void
    {
        if ($channel->type !== $type) {
            abort(404);
        }
    }
}
