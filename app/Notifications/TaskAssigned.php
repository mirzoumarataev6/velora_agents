<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Database channel only — no mail/push driver, and nothing queued: see
 * ProjectTaskController for why this is dispatched with Notification::sendNow.
 *
 * Stores classified data, never a prebuilt sentence: same convention as
 * DatabaseConnectionException::$reason / BiIngestionException::$reason. A
 * future bell renders `board_name`/`task_title` through ru/tg/en at display
 * time, the same way `bi.errors.{code}` is resolved from a stored code — a
 * notification row must stay meaningful if the recipient's locale changes
 * between being notified and reading it.
 */
class TaskAssigned extends Notification
{
    public function __construct(
        private readonly int $taskId,
        private readonly int $boardId,
        private readonly int $teamId,
        private readonly string $taskTitle,
        private readonly string $boardName,
        private readonly int $assignedById,
        private readonly string $assignedByName,
        private readonly ?string $dueAt,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'task_id' => $this->taskId,
            'board_id' => $this->boardId,
            'team_id' => $this->teamId,
            'task_title' => $this->taskTitle,
            'board_name' => $this->boardName,
            'assigned_by_id' => $this->assignedById,
            'assigned_by_name' => $this->assignedByName,
            'due_at' => $this->dueAt,
        ];
    }
}
