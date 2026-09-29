<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Reminders. Deliberately the smallest thing that works: a title, a date, and
 * a checkbox — anything more is a project manager, and the owner already has a
 * board for the work that has structure.
 */
class TaskController extends Controller
{
    /** The only four slices of a to-do list anybody actually uses. */
    protected const FILTERS = ['open', 'today', 'overdue', 'done'];

    protected const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $filter = in_array($request->query('filter'), self::FILTERS, true)
            ? $request->query('filter')
            : self::FILTERS[0];

        $tasks = Task::ownedBy($request->user())
            ->when($filter === 'open', fn ($q) => $q->open())
            ->when($filter === 'today', fn ($q) => $q->dueToday())
            ->when($filter === 'overdue', fn ($q) => $q->overdue())
            ->when($filter === 'done', fn ($q) => $q->whereNotNull('done_at'))
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
            'customers' => Customer::ownedBy($request->user())
                ->orderByDesc('last_contact_at')->limit(200)->get(['id', 'name', 'phone']),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $ownerId = $request->user()->id;
        $dealExists = Rule::exists('deals', 'id')->where('user_id', $ownerId);

        // When both references are supplied, they must describe the same
        // customer. A task may still be linked to only a deal or to neither.
        if ($request->filled('customer_id')) {
            $dealExists->where('customer_id', $request->input('customer_id'));
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'due_at' => ['nullable', 'date'],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('user_id', $ownerId)],
            'deal_id' => ['nullable', 'integer', $dealExists],
        ], [
            'customer_id.exists' => __('crm.tasks.customer_invalid'),
            'deal_id.exists' => __('crm.tasks.deal_invalid'),
        ]);

        $task = new Task;

        $task->forceFill([
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'due_at' => $data['due_at'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'deal_id' => $data['deal_id'] ?? null,
        ])->save();

        if ($request->wantsJson()) {
            return response()->json([
                'task' => [
                    'id' => $task->id,
                    'title' => $task->title,
                    'due_at' => $task->due_at?->translatedFormat('d M, H:i'),
                    'overdue' => $task->isOverdue(),
                ],
            ], 201);
        }

        return back()->with('status', __('crm.tasks.created'));
    }

    /**
     * Edit, or tick off. Ticking is the common case and arrives as `done`
     * alone, so everything else is optional.
     */
    public function update(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('update', $task);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'due_at' => ['nullable', 'date'],
            'done' => ['nullable', 'boolean'],
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

        $task->forceFill($changes)->save();

        return back()->with('status', __('crm.tasks.saved'));
    }

    public function destroy(Request $request, Task $task): RedirectResponse
    {
        $this->authorize('delete', $task);

        $task->delete();

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
