<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Services\TelegramNotifyLinker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class LeadNotificationController extends Controller
{
    public function __construct(protected TelegramNotifyLinker $linker) {}

    public function link(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize('update', $agent);

        try {
            $link = $this->linker->begin($agent);
        } catch (RuntimeException) {
            return response()->json([
                'message' => __('leads.notify.telegram_required'),
            ], 422);
        }

        return response()->json($link);
    }

    public function status(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize('update', $agent);

        return response()->json($this->serialize($agent));
    }

    public function unlink(Request $request, Agent $agent): JsonResponse
    {
        $this->authorize('update', $agent);

        $this->linker->unlink($agent);

        return response()->json($this->serialize($agent->refresh()));
    }

    protected function serialize(Agent $agent): array
    {
        return [
            'linked' => $agent->notifiesOwnerViaTelegram(),
            'username' => $agent->notify_telegram_username,
            'linked_at' => $agent->notify_telegram_linked_at?->toIso8601String(),
        ];
    }
}
