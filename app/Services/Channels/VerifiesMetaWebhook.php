<?php

namespace App\Services\Channels;

use App\Models\Channel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

trait VerifiesMetaWebhook
{
    // Подтверждает вебхук Meta, сверяя токен верификации и возвращая challenge
    public function verifyWebhook(Request $request, Channel $channel): ?Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $expected = $channel->credentials['verify_token'] ?? null;

        if ($mode !== 'subscribe' || empty($expected) || ! hash_equals((string) $expected, (string) $token)) {
            return response('Forbidden', 403);
        }

        return response((string) $challenge, 200);
    }

    // Проверяет подлинность входящего запроса по подписи X-Hub-Signature-256
    public function authenticateIncomingRequest(Request $request, Channel $channel): bool
    {
        $appSecret = $channel->credentials['app_secret'] ?? null;

        if (empty($appSecret)) {
            Log::warning('Meta webhook rejected: this channel has no app secret stored, so its deliveries cannot be verified. Reconnect the channel to supply one.', [
                'channel_id' => $channel->id,
                'channel_type' => $channel->type,
            ]);

            return false;
        }

        $header = (string) $request->header('X-Hub-Signature-256');

        if (! str_starts_with($header, 'sha256=')) {
            Log::warning('Meta webhook rejected: missing or malformed X-Hub-Signature-256 header.', [
                'channel_id' => $channel->id,
                'channel_type' => $channel->type,
            ]);

            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) $appSecret);

        if (! hash_equals($expected, $header)) {
            Log::warning('Meta webhook rejected: signature did not match the stored app secret.', [
                'channel_id' => $channel->id,
                'channel_type' => $channel->type,
            ]);

            return false;
        }

        return true;
    }
}
