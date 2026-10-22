<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Jobs\HandleIncomingMessageJob;
use App\Models\Channel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InstagramIncomingController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $data = $request->validate([

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
