<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Jobs\HandleIncomingMessageJob;
use App\Models\Channel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Entry point for the Python instagram_service microservice's polling loop —
 * see InstagrapiDriver's class doc for the full shape of this integration.
 * Reachable only from localhost (RestrictToLocalhost, applied in
 * bootstrap/app.php's routing `then` callback around routes/internal.php).
 */
class InstagramIncomingController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $data = $request->validate([
            // The Python service is multi-tenant (one session per channel),
            // so it tags every forwarded message with the channel_uuid whose
            // session it came from — that is the only way to tell which
            // agent owner's account this message belongs to.
            'channel_uuid' => ['required', 'uuid'],
            'from_user_id' => ['required', 'string'],
            'from_username' => ['nullable', 'string'],
            'text' => ['required', 'string'],
            'message_id' => ['required', 'string'],
        ]);

        $channel = Channel::query()
            ->where('type', 'instagrapi')
            ->where('uuid', $data['channel_uuid'])
            ->where('status', 'connected')
            ->first();

        if (! $channel) {
            return response()->json(['ok' => false, 'error' => 'No connected Instagram (instagrapi) channel with that uuid.'], 404);
        }

        HandleIncomingMessageJob::dispatch($channel, $data);

        return response()->json(['ok' => true]);
    }
}
