<?php

namespace App\Http\Controllers;

use App\Notifications\TaskAssigned;
use App\Services\Projects\TaskNotificationFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON API for project task-assignment notifications. No bell/view consumes
 * this yet — that is the next stage; this stage only reads and marks the
 * rows a signed-in user already has.
 */
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
        // Scoped through the user's OWN notifications() relation, never
        // DatabaseNotification::find($id): another user's notification id
        // must 404 here, not be read or touched — nothing below trusts the
        // id alone to mean "this caller's row".
        $notification = $request->user()->notifications()->whereKey($id)->first();
        abort_if($notification === null, 404);

        // Idempotent, and deliberately NOT re-run through the feed's
        // visibility filter: hiding a stale row from the LIST (team left,
        // board lost, task gone) is not the same question as whether the
        // caller may still mark their own row read, and marking an
        // already-read or already-hidden one is harmless either way.
        $notification->markAsRead();

        return response()->json(['status' => 'ok']);
    }
}
