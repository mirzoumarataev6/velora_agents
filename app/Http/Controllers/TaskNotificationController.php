<?php

namespace App\Http\Controllers;

use App\Notifications\TaskAssigned;
use App\Services\Projects\TaskNotificationFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskNotificationController extends Controller
{
    public function index(Request $request, TaskNotificationFeed $feed): JsonResponse
    {
        return response()->json($feed->build($request->user()));
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->notifications()
            ->where('type', TaskAssigned::class)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['status' => 'ok']);
    }

    public function read(Request $request, string $id): JsonResponse
    {

        $notification = $request->user()->notifications()->whereKey($id)->first();
        abort_if($notification === null, 404);

        $notification->markAsRead();

        return response()->json(['status' => 'ok']);
    }
}
