<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\ProjectBoard;
use App\Models\ProjectChecklistItem;
use App\Models\ProjectColumn;
use App\Models\Task;
use App\Models\User;
use App\Services\Permissions\PermissionCatalog;
use App\Services\Projects\ProjectAccess;
use App\Services\Projects\ProjectTaskWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectTaskController extends Controller
{
    public function show(Request $request, ProjectBoard $board, Task $task, ProjectAccess $access): RedirectResponse
    {
        $this->taskOnBoard($request, $board, $task, $access);

        return redirect()->route('projects.boards.show', [$board, 'task' => $task->id]);
    }

    public function store(Request $request, ProjectBoard $board, ProjectAccess $access, ProjectTaskWorkflow $workflow): RedirectResponse|JsonResponse
    {
        $access->authorize($request->user(), $board, PermissionCatalog::PROJECTS_CREATE);
        $data = $request->validate($this->taskRules($board, false));
        $column = ProjectColumn::where('team_id', $board->team_id)->where('board_id', $board->id)
            ->findOrFail($data['column_id']);
        $this->checkCrmAccess($request, $data);
        $this->checkAssignees($request->user(), $board, $data['assignee_ids'] ?? []);

        $task = DB::transaction(function () use ($request, $board, $column, $data, $workflow): Task {
            $task = Task::forceCreate([
                'user_id' => $request->user()->id,
                'team_id' => $board->team_id,
                'project_board_id' => $board->id,
                'project_column_id' => $column->id,
                'title' => trim($data['title']),
                'description' => $data['description'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'type' => 'internal',
                'due_at' => $data['due_at'] ?? null,
                'done_at' => $column->is_done ? now() : null,
                'customer_id' => $data['customer_id'] ?? null,
                'deal_id' => $data['deal_id'] ?? null,
            ]);
            $this->syncAssignees($task, $data['assignee_ids'] ?? []);
            $this->syncLabels($task, $data['label_ids'] ?? []);
            $workflow->record($task, $request->user(), 'created');

            return $task;
        });

        if ($request->wantsJson()) {
            return response()->json(['id' => $task->id, 'url' => route('projects.tasks.show', [$board, $task])], 201);
        }

        return redirect()->route('projects.boards.show', [$board, 'task' => $task->id])
            ->with('status', __('projects.messages.task_created'));
    }

    public function update(Request $request, ProjectBoard $board, Task $task, ProjectAccess $access, ProjectTaskWorkflow $workflow): RedirectResponse
    {
        $this->taskOnBoard($request, $board, $task, $access, PermissionCatalog::PROJECTS_EDIT);
        $data = $request->validate($this->taskRules($board, true));
        if ($request->boolean('assignees_present')) {
            $data['assignee_ids'] ??= [];
        }
        if ($request->boolean('labels_present')) {
            $data['label_ids'] ??= [];
        }
        $this->checkCrmAccess($request, $data, $task);
        if (array_key_exists('assignee_ids', $data)) {
            Gate::forUser($request->user())->authorize(PermissionCatalog::PROJECTS_ASSIGN, [$board]);
            $this->checkAssignees($request->user(), $board, $data['assignee_ids']);
        }

        DB::transaction(function () use ($request, $task, $data, $workflow): void {
            $changes = [];
            foreach (['title', 'description', 'priority', 'due_at', 'customer_id', 'deal_id'] as $field) {
                if (array_key_exists($field, $data)) {
                    $changes[$field] = $field === 'title' ? trim($data[$field]) : $data[$field];
                }
            }
            $task->forceFill($changes)->save();
            if (array_key_exists('assignee_ids', $data)) {
                $this->syncAssignees($task, $data['assignee_ids']);
            }
            if (array_key_exists('label_ids', $data)) {
                $this->syncLabels($task, $data['label_ids']);
            }
            $workflow->record($task, $request->user(), 'updated', null, ['fields' => array_keys($changes)]);
        });

        return back()->with('status', __('projects.messages.task_saved'));
    }

    public function move(Request $request, ProjectBoard $board, Task $task, ProjectAccess $access, ProjectTaskWorkflow $workflow): RedirectResponse|JsonResponse
    {
        $this->taskOnBoard($request, $board, $task, $access, PermissionCatalog::PROJECTS_EDIT);
        $data = $request->validate([
            'column_id' => ['required', 'integer', Rule::exists('project_columns', 'id')->where('team_id', $board->team_id)->where('board_id', $board->id)],
            'before_task_id' => ['nullable', 'integer'],
        ]);
        $column = ProjectColumn::where('team_id', $board->team_id)->where('board_id', $board->id)->findOrFail($data['column_id']);
        $moved = $workflow->move($task, $column, $data['before_task_id'] ?? null, $request->user());

        if ($request->wantsJson()) {
            return response()->json(['id' => $moved->id, 'column_id' => $moved->project_column_id, 'position' => $moved->position]);
        }

        return back()->with('status', __('projects.messages.task_moved'));
    }

    public function archive(Request $request, ProjectBoard $board, Task $task, ProjectAccess $access, ProjectTaskWorkflow $workflow): RedirectResponse
    {
        $this->taskOnBoard($request, $board, $task, $access, PermissionCatalog::PROJECTS_EDIT, true);
        DB::transaction(fn () => $task->forceFill(['archived_at' => $task->archived_at ? null : now()])->save());
        $workflow->record($task, $request->user(), $task->archived_at ? 'archived' : 'restored');

        return redirect()->route('projects.boards.show', $board)->with('status', __('projects.messages.task_saved'));
    }

    public function storeChecklist(Request $request, ProjectBoard $board, Task $task, ProjectAccess $access): RedirectResponse
    {
        $this->taskOnBoard($request, $board, $task, $access, PermissionCatalog::PROJECTS_EDIT);
        $data = $request->validate(['title' => ['required', 'string', 'max:255'], 'due_at' => ['nullable', 'date']]);
        $task->checklistItems()->create([
            'team_id' => $board->team_id,
            'board_id' => $board->id,
            'title' => trim($data['title']),
            'due_at' => $data['due_at'] ?? null,
            'position' => ((int) $task->checklistItems()->max('position')) + 1000,
        ]);

        return back()->with('status', __('projects.messages.checklist_saved'));
    }

    public function toggleChecklist(Request $request, ProjectBoard $board, Task $task, ProjectChecklistItem $item, ProjectAccess $access): RedirectResponse
    {
        $this->taskOnBoard($request, $board, $task, $access, PermissionCatalog::PROJECTS_EDIT);
        abort_unless($item->task_id === $task->id && $item->board_id === $board->id && $item->team_id === $board->team_id, 404);
        $item->forceFill(['completed_at' => $item->completed_at ? null : now()])->save();

        return back();
    }

    public function destroyChecklist(Request $request, ProjectBoard $board, Task $task, ProjectChecklistItem $item, ProjectAccess $access): RedirectResponse
    {
        $this->taskOnBoard($request, $board, $task, $access, PermissionCatalog::PROJECTS_EDIT);
        abort_unless($item->task_id === $task->id && $item->board_id === $board->id && $item->team_id === $board->team_id, 404);
        $item->delete();

        return back();
    }

    public function comment(Request $request, ProjectBoard $board, Task $task, ProjectAccess $access, ProjectTaskWorkflow $workflow): RedirectResponse
    {
        $this->taskOnBoard($request, $board, $task, $access, PermissionCatalog::PROJECTS_COMMENT);
        $data = $request->validate(['body' => ['required', 'string', 'max:10000']]);
        $workflow->record($task, $request->user(), 'comment', trim($data['body']));

        return back()->with('status', __('projects.messages.comment_added'));
    }

    private function taskOnBoard(Request $request, ProjectBoard $board, Task $task, ProjectAccess $access, string $permission = PermissionCatalog::PROJECTS_VIEW, bool $allowArchived = false): void
    {
        $access->authorize($request->user(), $board, $permission);
        abort_unless($task->team_id === $board->team_id && $task->project_board_id === $board->id, 404);
        abort_if($task->archived_at !== null && $permission !== PermissionCatalog::PROJECTS_VIEW && ! $allowArchived, 403);
    }

    private function taskRules(ProjectBoard $board, bool $partial): array
    {
        return [
            'column_id' => $partial ? ['prohibited'] : ['required', 'integer', Rule::exists('project_columns', 'id')->where('team_id', $board->team_id)->where('board_id', $board->id)],
            'title' => $partial ? ['sometimes', 'required', 'string', 'max:255'] : ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'priority' => ['sometimes', Rule::in(Task::PRIORITIES)],
            'customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customers', 'id')->where('team_id', $board->team_id)],
            'deal_id' => ['sometimes', 'nullable', 'integer', Rule::exists('deals', 'id')->where('team_id', $board->team_id)],
            'assignee_ids' => ['sometimes', 'array'],
            'assignee_ids.*' => ['integer', 'distinct', Rule::exists('team_user', 'user_id')->where('team_id', $board->team_id)],
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['integer', 'distinct', Rule::exists('project_labels', 'id')->where('team_id', $board->team_id)->where('board_id', $board->id)],
        ];
    }

    private function checkCrmAccess(Request $request, array $data, ?Task $task = null): void
    {
        if (filled($data['customer_id'] ?? null) || filled($data['deal_id'] ?? null)) {
            $this->authorize(PermissionCatalog::CRM_VIEW);
        }
        $customerId = array_key_exists('customer_id', $data) ? $data['customer_id'] : $task?->customer_id;
        $dealId = array_key_exists('deal_id', $data) ? $data['deal_id'] : $task?->deal_id;
        if (filled($customerId) && filled($dealId)
            && ! DB::table('deals')->where('id', $dealId)
                ->where('team_id', $request->user()->activeTeamId())->where('customer_id', $customerId)->exists()) {
            throw ValidationException::withMessages(['deal_id' => __('projects.errors.deal_customer_mismatch')]);
        }
    }

    private function checkAssignees(User $actor, ProjectBoard $board, array $ids): void
    {
        if ($ids !== []) {
            Gate::forUser($actor)->authorize(PermissionCatalog::PROJECTS_ASSIGN, [$board]);
        }
        foreach ($ids as $id) {
            $member = User::findOrFail($id);
            if (! Gate::forUser($member)->allows(PermissionCatalog::PROJECTS_VIEW, [$board])
                || ($board->visibility === 'private' && $board->created_by !== $member->id
                    && ! DB::table('project_board_members')->where('board_id', $board->id)->where('user_id', $member->id)->exists())) {
                throw ValidationException::withMessages(['assignee_ids' => __('projects.errors.assignee_cannot_view')]);
            }
        }
    }

    private function syncAssignees(Task $task, array $ids): void
    {
        DB::table('project_task_assignees')->where('task_id', $task->id)->delete();
        if ($ids !== []) {
            DB::table('project_task_assignees')->insert(array_map(fn (int $id) => [
                'team_id' => $task->team_id, 'board_id' => $task->project_board_id,
                'task_id' => $task->id, 'user_id' => $id,
                'created_at' => now(), 'updated_at' => now(),
            ], $ids));
        }
    }

    private function syncLabels(Task $task, array $ids): void
    {
        DB::table('project_task_labels')->where('task_id', $task->id)->delete();
        if ($ids !== []) {
            DB::table('project_task_labels')->insert(array_map(fn (int $id) => [
                'team_id' => $task->team_id, 'board_id' => $task->project_board_id,
                'task_id' => $task->id, 'label_id' => $id,
            ], $ids));
        }
    }
}
