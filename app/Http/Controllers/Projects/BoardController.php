<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\ProjectBoard;
use App\Models\ProjectColumn;
use App\Models\ProjectLabel;
use App\Models\Task;
use App\Models\TeamMembership;
use App\Services\Permissions\PermissionCatalog;
use App\Services\Projects\ProjectAccess;
use App\Services\Projects\ProjectBoards;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BoardController extends Controller
{
    private const CARDS_PER_COLUMN = 50;

    private const ACCENTS = ['blue', 'violet', 'emerald', 'amber', 'rose'];

    private const FILTERS = ['all', 'mine', 'overdue', 'soon', 'archived'];

    public function index(Request $request, ProjectAccess $access, ProjectBoards $boards): View
    {
        $this->authorize(PermissionCatalog::PROJECTS_VIEW);
        $teamId = $request->user()->activeTeamId();
        abort_if($teamId === null, 403);

        if ($request->user()->can(PermissionCatalog::PROJECTS_MANAGE)
            && ! ProjectBoard::where('team_id', $teamId)->exists()) {
            $boards->defaultForTeam($teamId, $request->user()->id);
        }

        return view('projects.index', [
            'i18nExtraNamespaces' => ['projects'],
            'boards' => $access->visibleBoards($request->user())
                ->withCount(['tasks as open_tasks_count' => fn ($query) => $query->whereNull('done_at')->whereNull('archived_at'),
                    'tasks as total_tasks_count' => fn ($query) => $query->whereNull('archived_at')])
                ->orderByDesc('updated_at')->get(),
            'archivedBoards' => $request->user()->can(PermissionCatalog::PROJECTS_MANAGE)
                ? ProjectBoard::where('team_id', $teamId)->whereNotNull('archived_at')->orderByDesc('archived_at')->get()
                : collect(),
        ]);
    }

    public function myWork(Request $request, ProjectAccess $access): View
    {
        $this->authorize(PermissionCatalog::PROJECTS_VIEW);
        $teamId = $request->user()->activeTeamId();
        abort_if($teamId === null, 403);

        $tasks = Task::query()->where('tasks.team_id', $teamId)->whereNull('tasks.archived_at')
            ->whereNull('tasks.done_at')
            ->whereIn('tasks.project_board_id', $access->visibleBoards($request->user())->select('project_boards.id'))
            ->where(function ($query) use ($request) {
                $query->where('tasks.user_id', $request->user()->id)
                    ->orWhereHas('assignees', fn ($members) => $members->where('users.id', $request->user()->id));
            })
            ->with(['projectBoard:id,name', 'projectColumn:id,name', 'assignees:id,name'])
            ->orderByRaw('tasks.due_at is null')->orderBy('tasks.due_at')->orderBy('tasks.id')
            ->paginate(30);

        return view('projects.my-work', [
            'i18nExtraNamespaces' => ['projects'],
            'tasks' => $tasks,
        ]);
    }

    public function show(Request $request, ProjectBoard $board, ProjectAccess $access): View
    {
        $access->authorize($request->user(), $board);
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : 'all';
        $search = trim((string) $request->query('q', ''));
        $search = mb_substr($search, 0, 100);

        $columns = $board->columns()->get();
        $pages = collect();
        foreach ($columns as $column) {
            // Each column has its own page cursor. A large board must never
            // hydrate every card, assignee and checklist just to paint the
            // first screen; a single global page would hide whole columns.
            $page = $column->tasks()->where('team_id', $board->team_id)
                ->when($filter === 'archived', fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))
                ->when($search !== '', fn ($q) => $q->where('title', 'like', '%'.addcslashes($search, '%_\\').'%'))
                ->when($filter === 'mine', fn ($q) => $q->whereHas('assignees', fn ($members) => $members->where('users.id', $request->user()->id)))
                ->when($filter === 'overdue', fn ($q) => $q->whereNull('done_at')->where('due_at', '<', now()))
                ->when($filter === 'soon', fn ($q) => $q->whereNull('done_at')->whereBetween('due_at', [now(), now()->addDays(7)]))
                ->with(['assignees:id,name', 'projectLabels:id,name,color', 'checklistItems:id,task_id,completed_at'])
                ->orderBy('position')->orderBy('id')
                ->paginate(self::CARDS_PER_COLUMN, ['*'], 'column_'.$column->id)->withQueryString();
            $column->setRelation('tasks', $page->getCollection());
            $pages->put($column->id, $page);
        }

        $selectedId = $request->query('task');
        abort_if($selectedId !== null && (! is_scalar($selectedId) || ! ctype_digit((string) $selectedId)), 404);
        $selectedTask = $selectedId === null ? null : Task::where('team_id', $board->team_id)
            ->where('project_board_id', $board->id)->whereKey((int) $selectedId)
            ->with(['assignees:id,name', 'projectLabels:id,name,color', 'checklistItems',
                'projectEvents' => fn ($events) => $events->with('actor:id,name')->limit(40),
                'customer:id,name', 'deal:id,title'])
            ->firstOrFail();

        $memberships = TeamMembership::where('team_id', $board->team_id)->with(['user:id,name,email', 'role:id,team_id,is_owner'])->get();
        $viewRoleIds = DB::table('role_permission')->whereIn('role_id', $memberships->pluck('role_id'))
            ->where('permission', PermissionCatalog::PROJECTS_VIEW)->pluck('role_id')->all();
        $members = $memberships->filter(fn (TeamMembership $membership) => $membership->role->is_owner
            || in_array($membership->role_id, $viewRoleIds, true));

        $canViewCrm = $request->user()->can(PermissionCatalog::ANALYTICS_VIEW);
        $customers = collect();
        $deals = collect();
        if ($selectedTask !== null && $canViewCrm) {
            $customers = Customer::where('team_id', $board->team_id)->orderByDesc('id')->limit(100)->get(['id', 'name']);
            $deals = Deal::where('team_id', $board->team_id)->orderByDesc('id')->limit(100)->get(['id', 'title', 'customer_id']);
            // A long-lived card can refer to a customer outside the recent
            // dropdown slice. Keep that option visible so saving the card
            // never clears its link merely because newer rows arrived.
            if ($selectedTask->customer_id && ! $customers->contains('id', $selectedTask->customer_id)) {
                $customer = Customer::withTrashed()->where('team_id', $board->team_id)->find($selectedTask->customer_id);
                if ($customer !== null) {
                    $customers->push($customer);
                }
            }
            if ($selectedTask->deal_id && ! $deals->contains('id', $selectedTask->deal_id)) {
                $deal = Deal::withTrashed()->where('team_id', $board->team_id)->find($selectedTask->deal_id);
                if ($deal !== null) {
                    $deals->push($deal);
                }
            }
        }

        $canEdit = $board->archived_at === null && $request->user()->can(PermissionCatalog::PROJECTS_EDIT, $board);

        return view('projects.board', [
            'i18nExtraNamespaces' => ['projects'],
            'board' => $board,
            'columns' => $columns,
            'pages' => $pages,
            'dragEnabled' => $filter === 'all' && $search === ''
                && $pages->every(fn ($page) => $page->total() <= self::CARDS_PER_COLUMN),
            'labels' => $board->labels()->orderBy('name')->get(),
            'members' => $members,
            'selectedTask' => $selectedTask,
            'archivedCount' => Task::where('team_id', $board->team_id)
                ->where('project_board_id', $board->id)->whereNotNull('archived_at')->count(),
            'filter' => $filter,
            'search' => $search,
            'filterActive' => $filter !== 'all' || $search !== '',
            'canEdit' => $canEdit,
            'canCreate' => $board->archived_at === null && $request->user()->can(PermissionCatalog::PROJECTS_CREATE, $board),
            'canManage' => $board->archived_at === null && $request->user()->can(PermissionCatalog::PROJECTS_MANAGE, $board),
            'canAssign' => $board->archived_at === null && $request->user()->can(PermissionCatalog::PROJECTS_ASSIGN, $board),
            'canComment' => $board->archived_at === null && $request->user()->can(PermissionCatalog::PROJECTS_COMMENT, $board),
            'canViewCrm' => $canViewCrm,
            'customers' => $customers,
            'deals' => $deals,
        ]);
    }

    public function store(Request $request, ProjectBoards $boards): RedirectResponse
    {
        $this->authorize(PermissionCatalog::PROJECTS_MANAGE);
        $teamId = $request->user()->activeTeamId();
        abort_if($teamId === null, 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'visibility' => ['required', Rule::in(['team', 'private'])],
            'accent' => ['required', Rule::in(self::ACCENTS)],
        ]);

        $board = $boards->create($teamId, $request->user()->id, trim($data['name']),
            $data['description'] ?? null, $data['visibility'], $data['accent']);

        return redirect()->route('projects.boards.show', $board)->with('status', __('projects.messages.board_created'));
    }

    public function update(Request $request, ProjectBoard $board, ProjectAccess $access): RedirectResponse
    {
        $access->authorize($request->user(), $board, PermissionCatalog::PROJECTS_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'visibility' => ['required', Rule::in(['team', 'private'])],
            'accent' => ['required', Rule::in(self::ACCENTS)],
        ]);
        DB::transaction(function () use ($board, $data, $request): void {
            $wasTeamWide = $board->visibility === 'team';
            $board->fill($data)->save();
            if ($board->visibility !== 'private') {
                return;
            }
            $memberIds = [$request->user()->id];
            if ($wasTeamWide) {
                // Closing a board must not strand people already assigned
                // to its cards. The manager can remove them afterward.
                $memberIds = array_merge($memberIds, DB::table('project_task_assignees')
                    ->where('board_id', $board->id)->distinct()->pluck('user_id')->all());
            }
            DB::table('project_board_members')->insertOrIgnore(array_map(fn (int $id) => [
                'team_id' => $board->team_id, 'board_id' => $board->id,
                'user_id' => $id, 'created_at' => now(), 'updated_at' => now(),
            ], array_unique($memberIds)));
        });

        return back()->with('status', __('projects.messages.board_saved'));
    }

    public function archive(Request $request, ProjectBoard $board, ProjectAccess $access): RedirectResponse
    {
        $access->authorize($request->user(), $board);
        $this->authorize(PermissionCatalog::PROJECTS_MANAGE, $board);
        if ($board->slug === '_inbox' && $board->archived_at === null) {
            throw ValidationException::withMessages(['board' => __('projects.errors.default_board')]);
        }
        $board->forceFill(['archived_at' => $board->archived_at ? null : now()])->save();

        return redirect()->route('projects.index')->with('status', __('projects.messages.board_saved'));
    }

    public function addMember(Request $request, ProjectBoard $board, ProjectAccess $access): RedirectResponse
    {
        $access->authorize($request->user(), $board, PermissionCatalog::PROJECTS_MANAGE);
        $data = $request->validate(['user_id' => ['required', 'integer', Rule::exists('team_user', 'user_id')->where('team_id', $board->team_id)]]);
        DB::table('project_board_members')->insertOrIgnore([
            'team_id' => $board->team_id, 'board_id' => $board->id, 'user_id' => $data['user_id'],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('status', __('projects.messages.board_saved'));
    }

    public function removeMember(Request $request, ProjectBoard $board, int $userId, ProjectAccess $access): RedirectResponse
    {
        $access->authorize($request->user(), $board, PermissionCatalog::PROJECTS_MANAGE);
        abort_if($board->created_by === $userId, 422);
        DB::table('project_board_members')->where('board_id', $board->id)->where('user_id', $userId)->delete();

        return back()->with('status', __('projects.messages.board_saved'));
    }

    public function storeColumn(Request $request, ProjectBoard $board, ProjectAccess $access): RedirectResponse
    {
        $access->authorize($request->user(), $board, PermissionCatalog::PROJECTS_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_done' => ['sometimes', 'boolean'],
            'wip_limit' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $board->columns()->create([
            'team_id' => $board->team_id,
            'name' => trim($data['name']),
            'is_done' => (bool) ($data['is_done'] ?? false),
            'wip_limit' => $data['wip_limit'] ?? null,
            'position' => ((int) $board->columns()->max('position')) + 1,
        ]);

        return back()->with('status', __('projects.messages.column_created'));
    }

    public function updateColumn(Request $request, ProjectBoard $board, ProjectColumn $column, ProjectAccess $access): RedirectResponse
    {
        $access->authorize($request->user(), $board, PermissionCatalog::PROJECTS_MANAGE);
        abort_unless($column->team_id === $board->team_id && $column->board_id === $board->id, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'wip_limit' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $column->fill(['name' => trim($data['name']), 'wip_limit' => $data['wip_limit'] ?? null])->save();

        return back()->with('status', __('projects.messages.column_saved'));
    }

    public function destroyColumn(Request $request, ProjectBoard $board, ProjectColumn $column, ProjectAccess $access): RedirectResponse
    {
        $access->authorize($request->user(), $board, PermissionCatalog::PROJECTS_MANAGE);
        abort_unless($column->team_id === $board->team_id && $column->board_id === $board->id, 404);
        if ($column->tasks()->withTrashed()->exists()) {
            throw ValidationException::withMessages(['column' => __('projects.errors.column_not_empty')]);
        }
        if (! $board->columns()->where('is_done', $column->is_done)->whereKeyNot($column->id)->exists()) {
            throw ValidationException::withMessages(['column' => __('projects.errors.last_column')]);
        }
        $column->delete();

        return back()->with('status', __('projects.messages.column_deleted'));
    }

    public function reorderColumn(Request $request, ProjectBoard $board, ProjectColumn $column, ProjectAccess $access): RedirectResponse
    {
        $access->authorize($request->user(), $board, PermissionCatalog::PROJECTS_MANAGE);
        abort_unless($column->team_id === $board->team_id && $column->board_id === $board->id, 404);
        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);

        DB::transaction(function () use ($board, $column, $data): void {
            $columns = $board->columns()->lockForUpdate()->get();
            $index = $columns->search(fn (ProjectColumn $candidate) => $candidate->id === $column->id);
            $otherIndex = $index + ($data['direction'] === 'up' ? -1 : 1);
            if (! isset($columns[$otherIndex])) {
                return;
            }
            $other = $columns[$otherIndex];
            $oldPosition = $column->position;
            $column->forceFill(['position' => $other->position])->save();
            $other->forceFill(['position' => $oldPosition])->save();
        });

        return back()->with('status', __('projects.messages.column_saved'));
    }

    public function storeLabel(Request $request, ProjectBoard $board, ProjectAccess $access): RedirectResponse
    {
        $access->authorize($request->user(), $board, PermissionCatalog::PROJECTS_MANAGE);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['required', Rule::in(self::ACCENTS)],
        ]);
        $board->labels()->create(['team_id' => $board->team_id, ...$data]);

        return back()->with('status', __('projects.messages.label_created'));
    }

    public function destroyLabel(Request $request, ProjectBoard $board, ProjectLabel $label, ProjectAccess $access): RedirectResponse
    {
        $access->authorize($request->user(), $board, PermissionCatalog::PROJECTS_MANAGE);
        abort_unless($label->team_id === $board->team_id && $label->board_id === $board->id, 404);
        $label->delete();

        return back()->with('status', __('projects.messages.label_deleted'));
    }
}
