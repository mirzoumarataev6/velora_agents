<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmWorkspace;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Lightweight CRM follow-ups. Tasks stay deliberately smaller than a project
 * manager, while priority and type make the next action scannable at a glance.
 */
class TaskController extends Controller
{
    /** The only four slices of a to-do list anybody actually uses. */
    protected const FILTERS = ['open', 'today', 'overdue', 'done'];

    protected const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $workspaces = CrmWorkspace::ownedBy($request->user())->orderByDesc('is_default')->orderBy('name')->get();
        $requestedWorkspace = $request->query('workspace');
        $workspace = ctype_digit((string) $requestedWorkspace)
            ? $workspaces->firstWhere('id', (int) $requestedWorkspace)
            : null;
        $filter = in_array($request->query('filter'), self::FILTERS, true)
            ? $request->query('filter')
            : self::FILTERS[0];

        $tasks = Task::ownedBy($request->user())
            ->when($filter === 'open', fn ($q) => $q->open())
            ->when($filter === 'today', fn ($q) => $q->dueToday())
            ->when($filter === 'overdue', fn ($q) => $q->overdue())
            ->when($filter === 'done', fn ($q) => $q->whereNotNull('done_at'))
            ->when($workspace, fn ($q) => $q->where(function ($scope) use ($workspace) {
                $scope->whereHas('customer', fn ($customer) => $customer->where('crm_workspace_id', $workspace->id))
                    ->orWhereHas('deal', fn ($deal) => $deal->where('crm_workspace_id', $workspace->id));

                if ($workspace->is_default) {
                    $scope->orWhere(fn ($unassigned) => $unassigned->whereNull('customer_id')->whereNull('deal_id'));
                }
            }))
            ->with(['customer:id,name', 'deal:id,title'])
            // Soonest first, and undated tasks last: a date is a commitment and
            // outranks a someday.
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->appends(Arr::except($request->query(), ['page']));

        return view('crm.tasks.index', [
            'i18nExtraNamespaces' => ['crm'],
            'tasks' => $tasks,
            'activeFilter' => $filter,
            'counts' => self::counts($request->user()),
            'workspaces' => $workspaces,
            'activeWorkspace' => $workspace,
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $this->authorize('create', Task::class);

        $teamId = $request->user()->activeTeamId();
        $dealExists = Rule::exists('deals', 'id')->where('team_id', $teamId);

        // When both references are supplied, they must describe the same
        // customer. A task may still be linked to only a deal or to neither.
        if ($request->filled('customer_id')) {
            $dealExists->where('customer_id', $request->input('customer_id'));
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'due_at' => ['nullable', 'date'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('team_id', $teamId)],
            'new_customer_name' => ['nullable', 'string', 'max:255'],
            'deal_id' => ['nullable', 'integer', $dealExists],
            'priority' => ['nullable', 'string', Rule::in(Task::PRIORITIES)],
            'type' => ['nullable', 'string', Rule::in(Task::TYPES)],
        ], [
            'customer_id.exists' => __('crm.tasks.customer_invalid'),
            'deal_id.exists' => __('crm.tasks.deal_invalid'),
        ]);

        $task = DB::transaction(function () use ($request, $data, $teamId): Task {
            $customerId = $data['customer_id'] ?? null;

            if (blank($customerId) && filled($data['new_customer_name'] ?? null)) {
                $customer = Customer::forceCreate([
                    'user_id' => $request->user()->id,
                    // Explicit, not left to StampsTeam's own current_team_id
                    // fallback — the workspace below is already resolved
                    // against $teamId, and the new customer must land in
                    // the same one.
                    'team_id' => $teamId,
                    'crm_workspace_id' => CrmWorkspace::defaultFor($request->user(), $teamId)->id,
                    'name' => trim($data['new_customer_name']),
                    'status' => 'lead',
                ]);
                $customerId = $customer->id;
            }

            $task = new Task;
            $task->forceFill([
                'user_id' => $request->user()->id,
                // Explicit, same reasoning as the customer above.
                'team_id' => $teamId,
                'title' => $data['title'],
                'due_at' => $data['due_at'] ?? null,
                'customer_id' => $customerId,
                'deal_id' => $data['deal_id'] ?? null,
                'priority' => $data['priority'] ?? 'normal',
                'type' => $data['type'] ?? 'follow_up',
            ])->save();

            return $task;
        });

        if ($request->wantsJson()) {
            $task->load(['customer', 'deal']);

            return response()->json([
                'task' => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'due_at' => $task->due_at?->translatedFormat('d M, H:i'),
                    'overdue' => $task->isOverdue(),
                    'priority' => $task->priority,
                    'priority_label' => __('crm.tasks.priorities.'.$task->priority),
                    'type' => $task->type,
                    'done' => false,
                    'type_label' => __('crm.tasks.types.'.$task->type),
                    'url' => route('crm.tasks.update', $task),
                    'delete_url' => route('crm.tasks.destroy', $task),
                    'delete_label' => __('crm.tasks.delete'),
                    'delete_confirm' => __('crm.tasks.delete_confirm'),
                    'done_label' => __('crm.tasks.done_label'),
                    'no_due' => __('crm.tasks.no_due'),
                    'customer_name' => $task->customer?->displayName(),
                    'customer_url' => $task->customer ? route('crm.customers.show', $task->customer) : null,
                    'deal_title' => $task->deal?->title,
                ],
            ], 201);
        }

        return back()->with('status', __('crm.tasks.created'));
    }

    /**
     * Edit, or tick off. Ticking is the common case and arrives as `done`
     * alone, so everything else is optional.
     */
    public function update(Request $request, Task $task): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $task);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'due_at' => ['nullable', 'date'],
            'done' => ['nullable', 'boolean'],
            'priority' => ['sometimes', 'required', 'string', Rule::in(Task::PRIORITIES)],
            'type' => ['sometimes', 'required', 'string', Rule::in(Task::TYPES)],
        ]);

        $changes = array_filter([
            'title' => $data['title'] ?? null,
            'due_at' => $data['due_at'] ?? null,
        ], fn ($value) => $value !== null);

        if ($request->has('done')) {
            // Re-ticking an already-done task keeps its original completion
            // time: when it was finished is a fact, not a toggle.
            $changes['done_at'] = $request->boolean('done')
                ? ($task->done_at ?? now())
                : null;
        }

        foreach (['priority', 'type'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }

        $task->forceFill($changes)->save();

        if ($request->wantsJson()) {
            return response()->json(['message' => __('crm.tasks.saved'), 'done' => $task->isDone()]);
        }

        return back()->with('status', __('crm.tasks.saved'));
    }

    public function destroy(Request $request, Task $task): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $task);

        $task->delete();

        if ($request->wantsJson()) {
            return response()->json(['message' => __('crm.tasks.deleted')]);
        }

        return back()->with('status', __('crm.tasks.deleted'));
    }

    /**
     * What the widget shows. Public and static because the board renders it
     * too, and two counts of "how much is late" that could disagree would be
     * worse than none.
     *
     * @return array{overdue: int, today: int, open: int}
     */
    public static function counts(User $user): array
    {
        return [
            'overdue' => Task::ownedBy($user)->overdue()->count(),
            'today' => Task::ownedBy($user)->dueToday()->count(),
            'open' => Task::ownedBy($user)->open()->count(),
        ];
    }
}
