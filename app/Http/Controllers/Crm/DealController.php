<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmNote;
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
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use League\Csv\Bom;
use League\Csv\Writer;

/**
 * The deal list: where the owner actually works the pipeline.
 */
class DealController extends Controller
{
    /** The dropdown value that means "not one of these — make a new one". */
    protected const NEW_CUSTOMER = '__new__';

    /**
     * The stage-filter sentinel for "no deal at all" — not a real stage
     * (Deal::allStages() knows nothing about it), so DealBoard::list()
     * matches zero deals under it and the client renders leadsWithoutDeals()
     * in its place. See that method for why this bucket exists.
     */
    public const LEADS_BUCKET = 'leads';

    public function __construct(protected DealBoard $board, protected CustomerResolver $customers) {}

    public function index(Request $request): View
    {
        $defaultWorkspace = CrmWorkspace::defaultFor($request->user());
        $filters = $this->filters($request);
        $workspaces = CrmWorkspace::ownedBy($request->user())->orderByDesc('is_default')->orderBy('name')->get();
        $selectedWorkspace = $filters['workspace_id'] ? $workspaces->firstWhere('id', $filters['workspace_id']) : null;
        $showLeads = $filters['stage'] === self::LEADS_BUCKET;
        $hasLeadsBucket = $filters['origin'] === 'social' && $filters['workspace_id'] === null;
        $dealPage = $showLeads ? null : $this->board->list($request->user(), $filters);
        $leadPage = $showLeads ? $this->board->leadsWithoutDeals($request->user(), $filters) : null;
        $leadsCount = $showLeads ? $leadPage->total() : ($hasLeadsBucket ? $this->board->leadsWithoutDealsCount($request->user(), $filters) : 0);
        $deepLinkedDeal = ctype_digit((string) $request->query('deal'))
            ? Deal::ownedBy($request->user())->with(['customer', 'conversation.channel', 'tasks' => fn ($query) => $query->open()->orderBy('due_at')->limit(1)])->find($request->integer('deal'))
            : null;
        $initialDeal = $deepLinkedDeal ? $this->board->card(
            $deepLinkedDeal,
            // Both conditions — see DealBoard::list()'s identical purchase
            // count for why payment_status alone is not enough.
            $deepLinkedDeal->customer?->deals()->where('payment_status', 'paid')->where('stage', 'payment')->count() ?? 0,
            $deepLinkedDeal->customer?->conversations()->count() ?? 0,
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
            'stages' => Deal::allStages(),
            'cancelledStage' => Deal::STAGE_CANCELLED,
            'leadsBucket' => self::LEADS_BUCKET,
            'initialCustomer' => ctype_digit((string) old('customer_id'))
                ? Customer::ownedBy($request->user())->find(old('customer_id'))
                : null,
            'initialDeal' => $initialDeal,
        ]);
    }

    /**
     * The live feed: the same two unfiltered arrays the first paint
     * rendered, refetched whole. Not a diff — it is at most a few hundred
     * small rows, and a diff protocol would be a second description of the
     * list that could disagree with the first.
     */
    public function poll(Request $request): JsonResponse
    {
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

    /**
     * The board, exactly as filtered, as a CSV — every matching deal, not
     * just the one representative card per customer the board itself shows
     * (see DealBoard::list()'s own doc on why that grouping exists; an export
     * is read in a spreadsheet, where "one row per deal" is what is useful).
     */
    public function export(Request $request): Response
    {
        $this->authorize('export', Deal::class);

        $filters = $this->filters($request);

        $deals = $this->board->filteredDeals($request->user(), $filters)
            ->with(['customer:id,name,phone'])
            ->get();

        $csv = Writer::from(fopen('php://temp', 'w+'));
        $csv->setOutputBOM(Bom::Utf8);
        $csv->insertOne([
            __('crm.customers.directory.col_customer'), __('crm.board.add_title'), __('crm.board.col_amount'),
            __('crm.board.payment'), __('crm.board.col_stage'), __('crm.board.sales_origin'),
            __('crm.board.add_date'), __('crm.board.won_reason'), __('crm.board.timeline_field_cancelled_reason'), __('crm.board.note'),
        ]);

        foreach ($deals as $deal) {
            $csv->insertOne([
                $deal->customer?->displayName() ?? '',
                (string) $deal->title,
                (string) $deal->amount,
                __('crm.payment_statuses.'.$deal->payment_status),
                __('crm.stages.'.$deal->stage),
                __('crm.sales_origins.'.($deal->sales_origin ?: 'social')),
                (string) $deal->deal_date?->toDateString(),
                (string) $deal->won_reason,
                (string) $deal->cancelled_reason,
                (string) $deal->note,
            ]);
        }

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="deals.csv"',
        ]);
    }

    /** Customer-wide messages, deal changes and linked tasks for the detail panel. */
    public function activity(Request $request, Deal $deal): JsonResponse
    {
        $this->authorize('view', $deal);

        $messages = Message::whereHas('conversation', fn ($query) => $query->where('customer_id', $deal->customer_id))
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

        $tasks = Task::ownedBy($request->user())->where('deal_id', $deal->id)->latest('created_at')->limit(30)->get()
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
                'can_delete' => $request->user()->id === $note->user_id,
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
            'stage_changed' => ($activity->data['to'] ?? null) === Deal::STAGE_CANCELLED
                ? __('crm.board.timeline_cancelled', ['reason' => $activity->data['reason'] ?: __('crm.board.reason_unspecified')])
                : __('crm.board.timeline_stage', [
                    'from' => __('crm.stages.'.($activity->data['from'] ?? 'new_lead')),
                    'to' => __('crm.stages.'.($activity->data['to'] ?? 'new_lead')),
                ]),
            // The won counterpart to 'stage_changed''s cancelled branch above
            // — see DealObserver for why closing gets its own activity type.
            'closed' => __('crm.board.timeline_closed', [
                'reason' => $activity->data['reason'] ?: __('crm.board.reason_unspecified'),
            ]),
            'updated' => $this->activityChangesText($activity->data['changes'] ?? []),
            default => __('crm.board.timeline_updated'),
        };
    }

    /**
     * One readable line per field DealObserver recorded a change for — the
     * "updated" activity's `data['changes']` already carries a from/to pair
     * per field, this just renders it the way `timeline_stage` renders its
     * own from/to. A field the renderer doesn't recognise is skipped rather
     * than shown raw, so a future column added to the diff doesn't surface
     * as an untranslated array key.
     */
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

    /** A changed field's value, formatted the way it is shown everywhere else on the board. */
    private function activityFieldValue(string $field, mixed $value): string
    {
        if ($value === null || $value === '') {
            return __('crm.board.timeline_field_empty');
        }

        return match ($field) {
            // formatPrice() can itself return null (a zero or negative
            // amount), which this method must not pass through — it is
            // typed to always return a displayable string.
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

    /**
     * A deal the owner opens by hand, for business that did not arrive by
     * chat — and, in the same request, the customer for it too when they are
     * not in the book yet.
     *
     * `customer_id` carries either a real id or the sentinel NEW_CUSTOMER; a
     * plain `required|integer` rule would reject the sentinel outright, so
     * this only checks that SOMETHING was chosen and resolves what it means
     * below — the one place a request can still fail without a customer
     * having been picked at all.
     */
    public function store(Request $request): RedirectResponse
    {
        $isNew = $request->input('customer_id') === self::NEW_CUSTOMER;

        $data = $request->validate([
            // Not 'string': a real browser form always sends this as text,
            // but nothing here needs to insist on that shape — either it's
            // the NEW_CUSTOMER sentinel or an id, and both branches below
            // check the actual value rather than its PHP type.
            'customer_id' => ['required'],
            'new_customer_name' => [$isNew ? 'required' : 'nullable', 'string', 'max:255'],
            'new_customer_phone' => ['nullable', 'string', 'max:32'],
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'deal_date' => ['nullable', 'date'],
            'sales_origin' => ['sometimes', 'required', 'string', 'in:'.implode(',', Deal::SALES_ORIGINS)],
            'crm_workspace_id' => ['nullable', 'integer', Rule::exists('crm_workspaces', 'id')->where('user_id', $request->user()->id)],
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
            // Scoped, not a bare find: a customer id that exists but belongs
            // to somebody else must fail exactly like one that doesn't exist.
            $customer = Customer::ownedBy($request->user())->find($data['customer_id']);

            if ($customer === null) {
                return back()->withErrors(['customer_id' => __('crm.board.customer_required')])->withInput();
            }

            $matchedExisting = false;
        }

        $deal = new Deal;
        $deal->forceFill([
            'user_id' => $request->user()->id,
            'crm_workspace_id' => $data['crm_workspace_id'] ?? CrmWorkspace::defaultFor($request->user())->id,
            'customer_id' => $customer->id,
            // The customer's most recent thread, so the row can show a
            // channel icon like every other deal in the list. A brand-new
            // customer has none yet, and that is fine — the row just opens
            // without one, same as any manually opened deal always could.
            'conversation_id' => $customer->conversations()->latest('last_message_at')->value('id'),
            'title' => $data['title'],
            'amount' => $data['amount'],
            'deal_date' => $data['deal_date'] ?? now()->toDateString(),
            'sales_origin' => $data['sales_origin'] ?? 'social',
            'stage' => Deal::STAGES[0],
            'stage_changed_at' => now(),
            // Opened by hand, so it counts as the owner's own placement.
            'stage_set_by_owner_at' => now(),
        ])->save();

        return back()->with('status', $matchedExisting
            ? __('crm.board.created_matched', ['name' => $customer->displayName()])
            : __('crm.board.created'));
    }

    /**
     * Either the customer this phone number already belongs to, or a freshly
     * opened one.
     *
     * The phone is checked here too, not only in the live JS lookup: the
     * client-side check is a courtesy that can be skipped (no JS, a slow
     * network, a name typed before the phone finishes checking), but the
     * one rule that must always hold is "a phone number never gets a second
     * customer profile" — so the server enforces it independently, using the
     * exact same key CustomerResolver uses for every other merge in the CRM.
     *
     * @return array{0: Customer, 1: bool} the customer, and whether it was
     *                                     matched rather than created
     */
    private function resolveOrCreateCustomer(User $user, string $name, ?string $phone, ?int $workspaceId = null): array
    {
        $key = $this->customers->phoneKey($phone);

        if ($key !== null) {
            $existing = Customer::where('user_id', $user->id)->where('phone_key', $key)->first();

            if ($existing !== null) {
                return [$existing, true];
            }
        }

        // forceCreate: user_id is deliberately not mass-assignable, so no
        // request payload can file a customer under another account.
        $customer = Customer::forceCreate([
            'user_id' => $user->id,
            'crm_workspace_id' => $workspaceId ?? CrmWorkspace::defaultFor($user)->id,
            'name' => $name,
            'phone' => $key !== null ? $phone : null,
            'phone_key' => $key,
            // A person opened here, by the owner, rather than found by an
            // agent in a conversation — 'manual' says so, the same way
            // 'telegram'/'whatsapp'/'instagram' say a channel found them.
            'source' => 'manual',
            'status' => 'lead',
            'first_contact_at' => now(),
            'last_contact_at' => now(),
        ]);

        return [$customer, false];
    }

    /**
     * A stage change from the list's quick-select. Always allowed, whatever
     * the automation thinks: manual override is the point, and the stamp
     * this leaves is what stops a later signal undoing the owner's decision.
     */
    public function move(Request $request, Deal $deal): JsonResponse
    {
        $this->authorize('update', $deal);

        $data = $request->validate([
            'stage' => ['required', 'string', 'in:'.implode(',', Deal::allStages())],
            'position' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'cancelled_reason' => [$request->input('stage') === Deal::STAGE_CANCELLED ? 'required' : 'nullable', 'string', 'max:2000'],
        ], [
            'cancelled_reason.required' => __('crm.board.cancel_reason_required'),
        ]);

        $this->board->move($deal, $data['stage'], $data['position'] ?? null, byOwner: true, cancelledReason: $data['cancelled_reason'] ?? null);

        return response()->json(['deal' => $this->board->card($deal->fresh()->load('customer', 'conversation.channel'))]);
    }

    /**
     * The edit form: product, amount, payment status, note, stage, "Закрыто"
     * and the deal date, all from one save. A plain redirect for a no-JS
     * submission, or — the edit modal's own path — a JSON body when the
     * request says it wants one, so the list can update the row in place
     * without a reload.
     */
    public function update(Request $request, Deal $deal): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $deal);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            // 'paid' is not just another value here: it is the deal's
            // revenue and purchase-count queries' only signal that money
            // actually arrived (see CustomerController::index() and
            // DealBoard::list()). Оплата is what attests that — normally by
            // DealBoard::move() setting this automatically when a deal
            // enters the stage — so this field accepting 'paid' on its own,
            // independent of stage, let a deal be counted as a sale it
            // never reached. Allowed freely for 'unpaid'/'partial' (a
            // deposit while still haggling is real); 'paid' only once the
            // deal is actually there, whether already or in this same save.
            'payment_status' => ['required', 'string', 'in:'.implode(',', Deal::PAYMENT_STATUSES), function ($attribute, $value, $fail) use ($request, $deal) {
                $effectiveStage = $request->input('stage', $deal->stage);

                if ($value === 'paid' && $effectiveStage !== 'payment') {
                    $fail(__('crm.board.payment_status_requires_payment_stage'));
                }
            }],
            // 'sometimes': the edit form no longer sends this field at all
            // (replaced by the notes thread) — without 'sometimes' an absent
            // field still validates to null, and the update() below would
            // silently wipe the legacy column on every save.
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'stage' => ['nullable', 'string', 'in:'.implode(',', Deal::allStages())],
            'deal_date' => ['nullable', 'date'],
            'sales_origin' => ['sometimes', 'required', 'string', 'in:'.implode(',', Deal::SALES_ORIGINS)],
            'crm_workspace_id' => ['sometimes', 'required', 'integer', Rule::exists('crm_workspaces', 'id')->where('user_id', $request->user()->id)],
            'closed' => ['nullable', 'boolean'],
            'cancelled_reason' => [($request->input('stage', $deal->stage) === Deal::STAGE_CANCELLED ? 'required' : 'nullable'), 'string', 'max:2000'],
            // Optional, unlike cancelled_reason: a win needs no excuse.
            'won_reason' => ['nullable', 'string', 'max:2000'],
        ], [
            'title.required' => __('crm.board.title_required'),
            'amount.required' => __('crm.board.amount_required'),
            'amount.gt' => __('crm.board.amount_positive'),
            'cancelled_reason.required' => __('crm.board.cancel_reason_required'),
        ]);

        $deal->update(Arr::only($data, ['title', 'amount', 'payment_status', 'sales_origin', 'crm_workspace_id', 'note']));

        // Through the same door a drag used to go through, and the row's own
        // quick-select still does — the guard that keeps the automation from
        // overruling this only works if every hand move stamps the deal here.
        if (! empty($data['stage']) && $data['stage'] !== $deal->stage) {
            $this->board->move($deal, $data['stage'], byOwner: true, cancelledReason: $data['cancelled_reason'] ?? null);
        } elseif ($deal->stage === Deal::STAGE_CANCELLED && array_key_exists('cancelled_reason', $data)) {
            $deal->update(['cancelled_reason' => $data['cancelled_reason']]);
        }

        if (! empty($data['deal_date'])) {
            $this->board->setDealDate($deal, $data['deal_date']);
        }

        // Only when the field was actually sent — the edit form only ever
        // includes it while the deal sits in Оплата, and a save from any
        // other state must not be read as "clear the flag".
        if ($request->has('closed')) {
            $this->board->setClosed($deal, $request->boolean('closed'), $data['won_reason'] ?? null);
        }

        if ($request->wantsJson()) {
            return response()->json(['deal' => $this->board->card($deal->fresh()->load('customer', 'conversation.channel'))]);
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

    /**
     * The filter set the list starts from — shared by the first paint and
     * the poll, so a stage tab, a search term or a sort the owner picked
     * keeps holding across the 12-second refresh instead of resetting to
     * "everything" the next time the page quietly re-fetches.
     *
     * @return array{stage: string, q: ?string, sort: string, dir: string, origin: string, workspace_id: ?int}
     */
    private function filters(Request $request): array
    {
        $stage = $request->query('stage');
        $sort = $request->query('sort');
        $origin = in_array($request->query('origin'), Deal::SALES_ORIGINS, true) ? $request->query('origin') : 'social';
        $workspaceId = ctype_digit((string) $request->query('workspace')) && CrmWorkspace::ownedBy($request->user())->whereKey($request->query('workspace'))->exists()
            ? (int) $request->query('workspace')
            : null;
        $stage = in_array($stage, array_merge(['all', self::LEADS_BUCKET, 'in_progress', 'resolved'], Deal::allStages()), true) ? $stage : 'all';

        // People without deals have no origin or folder yet. Keep that bucket
        // available only in the online, account-wide view where its records
        // have a meaningful place.
        if ($stage === self::LEADS_BUCKET && ($origin !== 'social' || $workspaceId !== null)) {
            $stage = 'all';
        }

        return [
            'stage' => $stage,
            'q' => trim((string) $request->query('q')) ?: null,
            'sort' => in_array($sort, DealBoard::SORTS, true) ? $sort : 'date',
            // Online is the focused landing view; offline has its own explicit
            // tab so a mixed pipeline never makes the two revenue streams look
            // like one indistinguishable list.
            'origin' => $origin,
            'workspace_id' => $workspaceId,
            'dir' => $request->query('dir') === 'asc' ? 'asc' : 'desc',
            'page' => max(1, (int) $request->query('page', 1)),
        ];
    }
}
