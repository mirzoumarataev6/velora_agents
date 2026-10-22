<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmNote;
use App\Models\CrmStage;
use App\Models\CrmWorkspace;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Services\Crm\CustomerResolver;
use App\Services\Crm\DealBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use League\Csv\Bom;
use League\Csv\Writer;

class DealController extends Controller
{
    protected const NEW_CUSTOMER = '__new__';

    public const LEADS_BUCKET = 'leads';

    public function __construct(protected DealBoard $board, protected CustomerResolver $customers) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Deal::class);

        $defaultWorkspace = CrmWorkspace::defaultFor($request->user(), $request->user()->activeTeamId());
        $filters = $this->filters($request);
        $workspaces = CrmWorkspace::ownedBy($request->user())->orderByDesc('is_default')->orderBy('name')->get();
        $selectedWorkspace = $filters['workspace_id'] ? $workspaces->firstWhere('id', $filters['workspace_id']) : null;

        $stages = CrmStage::defaultsFor($request->user(), $request->user()->activeTeamId());
        $showLeads = $filters['stage'] === self::LEADS_BUCKET;
        $hasLeadsBucket = $filters['origin'] === 'social' && $filters['workspace_id'] === null;
        $dealPage = $showLeads ? null : $this->board->list($request->user(), $filters);
        $leadPage = $showLeads ? $this->board->leadsWithoutDeals($request->user(), $filters) : null;
        $leadsCount = $showLeads ? $leadPage->total() : ($hasLeadsBucket ? $this->board->leadsWithoutDealsCount($request->user(), $filters) : 0);
        $deepLinkedDeal = ctype_digit((string) $request->query('deal'))
            ? Deal::ownedBy($request->user())->with(['customer', 'conversation.channel', 'tasks' => fn ($query) => $query->visibleInCrm($request->user())->open()->orderBy('due_at')->limit(1)])->find($request->integer('deal'))
            : null;
        $initialDeal = $deepLinkedDeal ? $this->board->card(
            $deepLinkedDeal,
            $deepLinkedDeal->customer?->deals()->tap(fn ($q) => DealBoard::paidInWonStage()($q))->count() ?? 0,
            $deepLinkedDeal->customer?->conversations()->count() ?? 0,
            null,
            null,
            $request->user(),
        ) : null;

        return view('crm.board', [
            'i18nExtraNamespaces' => ['crm'],
            'deals' => $dealPage?->items() ?? [],
            'leads' => $leadPage?->items() ?? [],
            'pagination' => $this->pagination($showLeads ? $leadPage : $dealPage),
            'leadsCount' => $leadsCount,
            'summary' => $this->board->overview($request->user(), $filters),
            'filters' => $filters,
            'workspaces' => $workspaces,
            'selectedWorkspace' => $selectedWorkspace,
            'defaultWorkspaceId' => $defaultWorkspace->id,
            'stages' => $stages->pluck('name')->values()->all(),

            'stageLabels' => $stages->mapWithKeys(fn (CrmStage $stage) => [$stage->name => $stage->displayName()])->all(),
            'stageDotClasses' => $stages->mapWithKeys(fn (CrmStage $stage) => [$stage->name => CrmStage::dotClass($stage->color)])->all(),
            'cancelledStage' => $stages->firstWhere('role', 'lost')?->name,
            'wonStage' => $stages->firstWhere('role', 'won')?->name,
            'leadsBucket' => self::LEADS_BUCKET,
            'initialCustomer' => ctype_digit((string) old('customer_id'))
                ? Customer::ownedBy($request->user())->find(old('customer_id'))
                : null,
            'initialDeal' => $initialDeal,
        ]);
    }

    public function poll(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Deal::class);

        $filters = $this->filters($request);
        $showLeads = $filters['stage'] === self::LEADS_BUCKET;
        $hasLeadsBucket = $filters['origin'] === 'social' && $filters['workspace_id'] === null;
        $dealPage = $showLeads ? null : $this->board->list($request->user(), $filters);
        $leadPage = $showLeads ? $this->board->leadsWithoutDeals($request->user(), $filters) : null;
        $leadsCount = $showLeads ? $leadPage->total() : ($hasLeadsBucket ? $this->board->leadsWithoutDealsCount($request->user(), $filters) : 0);

        return response()->json([
            'deals' => $dealPage?->items() ?? [],
            'leads' => $leadPage?->items() ?? [],
            'pagination' => $this->pagination($showLeads ? $leadPage : $dealPage),
            'leadsCount' => $leadsCount,
            'summary' => $this->board->overview($request->user(), $filters),
        ]);
    }

    public function export(Request $request): Response
    {
        $this->authorize('export', Deal::class);

        $filters = $this->filters($request);

        $deals = $this->board->filteredDeals($request->user(), $filters)
            ->with(['customer:id,name,phone', 'crmStage'])
            ->get();

        $csv = Writer::from(fopen('php://temp', 'w+'));
        $csv->setOutputBOM(Bom::Utf8);
        $csv->insertOne([
            __('crm.customers.directory.col_customer'), __('crm.board.add_title'), __('crm.board.col_amount'),
            __('crm.board.payment'), __('crm.board.col_stage'), __('crm.board.sales_origin'),
            __('crm.board.add_date'), __('crm.board.won_reason'), __('crm.board.timeline_field_cancelled_reason'), __('crm.board.note'), __('crm.board.tags'),
        ]);

        foreach ($deals as $deal) {
            $csv->insertOne([
                $deal->customer?->displayName() ?? '',
                (string) $deal->title,
                (string) $deal->amount,
                __('crm.payment_statuses.'.$deal->payment_status),
                $deal->crmStage?->displayName() ?? $deal->stage,
                __('crm.sales_origins.'.($deal->sales_origin ?: 'social')),
                (string) $deal->deal_date?->toDateString(),
                (string) $deal->won_reason,
                (string) $deal->cancelled_reason,
                (string) $deal->note,
                implode(', ', $deal->tags ?? []),
            ]);
        }

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="deals.csv"',
        ]);
    }

    public function activity(Request $request, Deal $deal): JsonResponse
    {
        $this->authorize('view', $deal);

        $messages = Message::query()->when(! $request->user()->can('conversations.view', $deal), fn ($q) => $q->whereRaw('1 = 0'))->whereHas('conversation', fn ($query) => $query->where('customer_id', $deal->customer_id))
            ->with('conversation.channel:id,type')
            ->latest('created_at')->latest('id')->limit(30)->get()
            ->map(fn (Message $message) => [
                'kind' => 'message',
                'role' => $message->role,
                'text' => $message->content,
                'channel' => $message->conversation?->channel?->type,
                'at' => $message->created_at?->toIso8601String(),
                'label' => __('crm.board.timeline_message', ['role' => __('crm.roles.'.$message->role)]),
            ]);

        $activities = DealActivity::where('deal_id', $deal->id)->latest('created_at')->limit(50)->get()
            ->map(fn (DealActivity $activity) => [
                'kind' => 'activity',
                'type' => $activity->type,
                'text' => $this->activityText($activity),
                'at' => $activity->created_at?->toIso8601String(),
                'label' => __('crm.board.timeline_activity'),
            ]);

        $tasks = Task::visibleInCrm($request->user())->where('deal_id', $deal->id)->latest('created_at')->limit(30)->get()
            ->map(fn (Task $task) => [
                'kind' => 'task',
                'type' => $task->isDone() ? 'task_done' : 'task_created',
                'text' => $task->title,
                'at' => ($task->done_at ?? $task->created_at)?->toIso8601String(),
                'label' => $task->isDone() ? __('crm.board.timeline_task_done') : __('crm.board.timeline_task'),
            ]);

        $notes = $deal->notes()->with('user:id,name')->limit(50)->get()
            ->map(fn (CrmNote $note) => [
                'kind' => 'crm_note',
                'type' => $note->kind,
                'text' => $note->body,
                'author' => $note->user->name,
                'note_id' => $note->id,
                'can_delete' => $request->user()->can('delete', $note),
                'at' => $note->happened_at->toIso8601String(),
                'label' => __('crm.notes.kinds.'.$note->kind),
            ]);

        return response()->json([
            'items' => $messages->concat($activities)->concat($tasks)->concat($notes)
                ->sortByDesc('at')->take(60)->values(),
        ]);
    }

    private function activityText(DealActivity $activity): string
    {
        return match ($activity->type) {
            'created' => __('crm.board.timeline_created'),
            'stage_changed' => $this->activityIsToLostStage($activity)
                ? __('crm.board.timeline_cancelled', ['reason' => $activity->data['reason'] ?: __('crm.board.reason_unspecified')])
                : __('crm.board.timeline_stage', [
                    'from' => $this->activityStageLabel($activity, 'from'),
                    'to' => $this->activityStageLabel($activity, 'to'),
                ]),

            'closed' => __('crm.board.timeline_closed', [
                'reason' => $activity->data['reason'] ?: __('crm.board.reason_unspecified'),
            ]),
            'updated' => $this->activityChangesText($activity->data['changes'] ?? []),
            default => __('crm.board.timeline_updated'),
        };
    }

    private function activityIsToLostStage(DealActivity $activity): bool
    {
        return array_key_exists('to_role', $activity->data ?? [])
            ? $activity->data['to_role'] === 'lost'
            : ($activity->data['to'] ?? null) === Deal::STAGE_CANCELLED;
    }

    private function activityStageLabel(DealActivity $activity, string $key): string
    {
        $data = $activity->data ?? [];

        return $data[$key.'_label'] ?? __('crm.stages.'.($data[$key] ?? 'new_lead'));
    }

    private function activityChangesText(array $changes): string
    {
        $lines = [];

        foreach ($changes as $field => $change) {
            $label = match ($field) {
                'title' => __('crm.board.add_title'),
                'amount' => __('crm.board.amount'),
                'payment_status' => __('crm.board.payment'),
                'cancelled_reason' => __('crm.board.timeline_field_cancelled_reason'),
                'won_reason' => __('crm.board.won_reason'),
                default => null,
            };

            if ($label === null) {
                continue;
            }

            $lines[] = __('crm.board.timeline_field_change', [
                'field' => $label,
                'from' => $this->activityFieldValue($field, $change['from'] ?? null),
                'to' => $this->activityFieldValue($field, $change['to'] ?? null),
            ]);
        }

        return $lines === [] ? __('crm.board.timeline_updated') : implode("\n", $lines);
    }

    private function activityFieldValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return __('crm.board.timeline_field_empty');
        }

        return match ($field) {

            'amount' => Lead::formatPrice($value) ?? __('crm.board.timeline_field_empty'),
            'payment_status' => __('crm.payment_statuses.'.$value),
            default => (string) $value,
        };
    }

    private function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Deal::class);

        $isNew = $request->input('customer_id') === self::NEW_CUSTOMER;

        $data = $request->validate([

            'customer_id' => ['required'],
            'new_customer_name' => [$isNew ? 'required' : 'nullable', 'string', 'max:255'],
            'new_customer_phone' => ['nullable', 'string', 'max:32'],
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'deal_date' => ['nullable', 'date'],
            'sales_origin' => ['sometimes', 'required', 'string', 'in:'.implode(',', Deal::SALES_ORIGINS)],
            'crm_workspace_id' => ['nullable', 'integer', Rule::exists('crm_workspaces', 'id')->where('team_id', $request->user()->activeTeamId())],
        ], [
            'customer_id.required' => __('crm.board.customer_required'),
            'new_customer_name.required' => __('crm.board.customer_name_required'),
            'title.required' => __('crm.board.title_required'),
            'amount.required' => __('crm.board.amount_required'),
            'amount.gt' => __('crm.board.amount_positive'),
        ]);

        if ($isNew) {
            [$customer, $matchedExisting] = $this->resolveOrCreateCustomer(
                $request->user(),
                $data['new_customer_name'],
                $data['new_customer_phone'] ?? null,
                isset($data['crm_workspace_id']) ? (int) $data['crm_workspace_id'] : null,
            );
        } else {

            $customer = Customer::ownedBy($request->user())->find($data['customer_id']);

            if ($customer === null) {
                return back()->withErrors(['customer_id' => __('crm.board.customer_required')])->withInput();
            }

            $matchedExisting = false;
        }

        $teamId = $request->user()->activeTeamId();
        $initial = CrmStage::initialFor($request->user(), $teamId);

        $deal = new Deal;
        $deal->forceFill([
            'user_id' => $request->user()->id,

            'team_id' => $teamId,
            'crm_workspace_id' => $data['crm_workspace_id'] ?? CrmWorkspace::defaultFor($request->user(), $teamId)->id,
            'customer_id' => $customer->id,

            'conversation_id' => $customer->conversations()->latest('last_message_at')->value('id'),
            'title' => $data['title'],
            'amount' => $data['amount'],
            'deal_date' => $data['deal_date'] ?? now()->toDateString(),
            'sales_origin' => $data['sales_origin'] ?? 'social',
            'crm_stage_id' => $initial->id,
            'stage' => $initial->name,
            'stage_role' => $initial->role,
            'stage_changed_at' => now(),

            'stage_set_by_owner_at' => now(),
        ])->save();

        return back()->with('status', $matchedExisting
            ? __('crm.board.created_matched', ['name' => $customer->displayName()])
            : __('crm.board.created'));
    }

    private function resolveOrCreateCustomer(User $user, string $name, ?string $phone, ?int $workspaceId = null): array
    {
        $key = $this->customers->phoneKey($phone);
        $teamId = $user->activeTeamId();

        if ($key !== null) {

            $existing = Customer::where('team_id', $teamId)->where('phone_key', $key)->first();

            if ($existing !== null) {
                return [$existing, true];
            }
        }

        $customer = Customer::forceCreate([
            'user_id' => $user->id,

            'team_id' => $teamId,
            'crm_workspace_id' => $workspaceId ?? CrmWorkspace::defaultFor($user, $teamId)->id,
            'name' => $name,
            'phone' => $key !== null ? $phone : null,
            'phone_key' => $key,

            'source' => 'manual',
            'status' => 'lead',
            'first_contact_at' => now(),
            'last_contact_at' => now(),
        ]);

        return [$customer, false];
    }

    public function move(Request $request, Deal $deal): JsonResponse
    {
        $this->authorize('update', $deal);

        $stages = CrmStage::where('team_id', $deal->team_id)->get();
        $targetStage = $stages->firstWhere('name', $request->input('stage'));

        $data = $request->validate([
            'stage' => ['required', 'string', Rule::in($stages->pluck('name'))],
            'position' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'cancelled_reason' => [$targetStage?->role === 'lost' ? 'required' : 'nullable', 'string', 'max:2000'],
        ], [
            'cancelled_reason.required' => __('crm.board.cancel_reason_required'),
        ]);

        $this->board->move($deal, $targetStage, $data['position'] ?? null, byOwner: true, cancelledReason: $data['cancelled_reason'] ?? null);

        return response()->json(['deal' => $this->board->card($deal->fresh()->load('customer', 'conversation.channel'), viewer: $request->user())]);
    }

    public function update(Request $request, Deal $deal): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $deal);

        $stages = CrmStage::where('team_id', $deal->team_id)->get();
        $stageByName = fn (?string $name) => $stages->firstWhere('name', $name);
        $effectiveStage = $stageByName($request->input('stage', $deal->stage));

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],

            'payment_status' => ['required', 'string', 'in:'.implode(',', Deal::PAYMENT_STATUSES), function ($attribute, $value, $fail) use ($effectiveStage) {
                if ($value === 'paid' && $effectiveStage?->role !== 'won') {
                    $fail(__('crm.board.payment_status_requires_payment_stage'));
                }
            }],

            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'tags' => ['sometimes', 'nullable', 'string', 'max:500'],
            'stage' => ['nullable', 'string', Rule::in($stages->pluck('name'))],
            'deal_date' => ['nullable', 'date'],
            'sales_origin' => ['sometimes', 'required', 'string', 'in:'.implode(',', Deal::SALES_ORIGINS)],
            'crm_workspace_id' => ['sometimes', 'required', 'integer', Rule::exists('crm_workspaces', 'id')->where('team_id', $deal->team_id)],
            'closed' => ['nullable', 'boolean'],
            'cancelled_reason' => [$effectiveStage?->role === 'lost' ? 'required' : 'nullable', 'string', 'max:2000'],

            'won_reason' => ['nullable', 'string', 'max:2000'],
        ], [
            'title.required' => __('crm.board.title_required'),
            'amount.required' => __('crm.board.amount_required'),
            'amount.gt' => __('crm.board.amount_positive'),
            'cancelled_reason.required' => __('crm.board.cancel_reason_required'),
        ]);

        $updates = Arr::only($data, ['title', 'amount', 'payment_status', 'sales_origin', 'crm_workspace_id', 'note']);
        if (array_key_exists('tags', $data)) {
            $updates['tags'] = $this->parseTags($data['tags']);
        }
        $deal->update($updates);

        if (! empty($data['stage']) && $data['stage'] !== $deal->stage) {
            $this->board->move($deal, $stageByName($data['stage']), byOwner: true, cancelledReason: $data['cancelled_reason'] ?? null);
        } elseif ($deal->stage_role === 'lost' && array_key_exists('cancelled_reason', $data)) {
            $deal->update(['cancelled_reason' => $data['cancelled_reason']]);
        }

        if (! empty($data['deal_date'])) {
            $this->board->setDealDate($deal, $data['deal_date']);
        }

        if ($request->has('closed')) {
            $this->board->setClosed($deal, $request->boolean('closed'), $data['won_reason'] ?? null);
        }

        if ($request->wantsJson()) {
            return response()->json(['deal' => $this->board->card($deal->fresh()->load('customer', 'conversation.channel'), viewer: $request->user())]);
        }

        return back()->with('status', __('crm.board.saved'));
    }

    public function destroy(Request $request, Deal $deal): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $deal);

        $deal->delete();

        if ($request->wantsJson()) {
            return response()->json(['deleted' => true]);
        }

        return back()->with('status', __('crm.board.deleted'));
    }

    protected function parseTags(?string $raw): ?array
    {
        $tags = collect(explode(',', (string) $raw))
            ->map(fn (string $tag) => Str::squish($tag))
            ->filter()
            ->unique()
            ->take(20)
            ->values()
            ->all();

        return $tags ?: null;
    }

    private function filters(Request $request): array
    {
        $stage = $request->query('stage');
        $sort = $request->query('sort');
        $origin = in_array($request->query('origin'), Deal::SALES_ORIGINS, true) ? $request->query('origin') : 'social';
        $workspaceId = ctype_digit((string) $request->query('workspace')) && CrmWorkspace::ownedBy($request->user())->whereKey($request->query('workspace'))->exists()
            ? (int) $request->query('workspace')
            : null;
        $validStages = array_merge(
            ['all', self::LEADS_BUCKET, 'in_progress', 'resolved'],
            CrmStage::ownedBy($request->user())->pluck('name')->all(),
        );
        $stage = in_array($stage, $validStages, true) ? $stage : 'all';

        if ($stage === self::LEADS_BUCKET && ($origin !== 'social' || $workspaceId !== null)) {
            $stage = 'all';
        }

        return [
            'stage' => $stage,
            'q' => trim((string) $request->query('q')) ?: null,
            'tag' => trim((string) $request->query('tag')) ?: null,
            'sort' => in_array($sort, DealBoard::SORTS, true) ? $sort : 'date',

            'origin' => $origin,
            'workspace_id' => $workspaceId,
            'dir' => $request->query('dir') === 'asc' ? 'asc' : 'desc',
            'page' => max(1, (int) $request->query('page', 1)),
        ];
    }
}
