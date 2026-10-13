<?php

namespace App\Http\Controllers\Bi;

use App\Http\Controllers\Controller;
use App\Models\BiChatMessage;
use App\Models\BiDashboard;
use App\Services\Bi\Dashboard\AnalystChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalystChatController extends Controller
{
    public function store(Request $request, BiDashboard $dashboard, AnalystChat $analyst): JsonResponse
    {
        $this->authorize('update', $dashboard);
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']]);
        $reply = $analyst->reply($request->user(), $dashboard, $data['message']);

        return response()->json(['id' => $reply->id, 'text' => $reply->content,
            'effects' => $reply->payload['effects'] ?? [], 'undoable' => ! empty($reply->payload['undo'])]);
    }

    public function undo(Request $request, BiDashboard $dashboard, BiChatMessage $message, AnalystChat $analyst): JsonResponse
    {
        $this->authorize('update', $dashboard);
        $this->authorize('update', $message);
        abort_unless($message->bi_dashboard_id === $dashboard->id, 404);

        return response()->json($analyst->undo($request->user(), $dashboard, $message));
    }
}
