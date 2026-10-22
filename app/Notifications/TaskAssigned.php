<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

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

    public function via(object $notifiable): array
    {
        return ['database'];
    }

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
