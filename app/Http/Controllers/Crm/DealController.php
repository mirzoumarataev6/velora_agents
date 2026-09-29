<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\DealActivity;
use App\Models\Message;
use App\Models\Task;
use App\Models\User;
use App\Services\Crm\CustomerResolver;
use App\Services\Crm\DealBoard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\View\View;

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
        $filters = $this->filters($request);
        $showLeads = $filters['stage'] === self::LEADS_BUCKET;
        $dealPage = $showLeads ? null : $this->board->list($request->user(), $filters);
        $leadPage = $showLeads ? $this->board->leadsWithoutDeals($request->user(), $filters) : null;
        $leadsCount = $showLeads ? $leadPage->total() : $this->board->leadsWithoutDealsCount($request->user(), $filters);

        return view('crm.board', [
            'i18nExtraNamespaces' => ['crm'],
            'deals' => $dealPage?->items() ?? [],
            'leads' => $leadPage?->items() ?? [],
            'pagination' => $this->pagination($showLeads ? $leadPage : $dealPage),
            'leadsCount' => $leadsCount,
            'summary' => $this->board->overview($request->user()),
            'filters' => $filters,
            'stages' => Deal::allStages(),
            'cancelledStage' => Deal::STAGE_CANCELLED,
            'leadsBucket' => self::LEADS_BUCKET,
            'initialCustomer' => ctype_digit((string) old('customer_id'))
                ? Customer::ownedBy($request->user())->find(old('customer_id'))
                : null,
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
        $dealPage = $showLeads ? null : $this->board->list($request->user(), $filters);
        $leadPage = $showLeads ? $this->board->leadsWithoutDeals($request->user(), $filters) : null;
        $leadsCount = $showLeads ? $leadPage->total() : $this->board->leadsWithoutDealsCount($request->user(), $filters);

        return response()->json([
            'deals' => $dealPage?->items() ?? [],
            'leads' => $leadPage?->items() ?? [],
            'pagination' => $this->pagination($showLeads ? $leadPage : $dealPage),
            'leadsCount' => $leadsCount,
            'summary' => $this->board->overview($request->user()),
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

        return response()->json([
            'items' => $messages->concat($activities)->concat($tasks)
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
            default => __('crm.board.timeline_updated'),
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
            'customer_id' => $customer->id,
            // The customer's most recent thread, so the row can show a
            // channel icon like every other deal in the list. A brand-new
            // customer has none yet, and that is fine — the row just opens
            // without one, same as any manually opened deal always could.
            'conversation_id' => $customer->conversations()->latest('last_message_at')->value('id'),
            'title' => $data['title'],
            'amount' => $data['amount'],
            'deal_date' => $data['deal_date'] ?? now()->toDateString(),
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
    private function resolveOrCreateCustomer(User $user, string $name, ?string $phone): array
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
            'payment_status' => ['required', 'string', 'in:'.implode(',', Deal::PAYMENT_STATUSES)],
            'note' => ['nullable', 'string', 'max:5000'],
            'stage' => ['nullable', 'string', 'in:'.implode(',', Deal::allStages())],
            'deal_date' => ['nullable', 'date'],
            'closed' => ['nullable', 'boolean'],
            'cancelled_reason' => [($request->input('stage', $deal->stage) === Deal::STAGE_CANCELLED ? 'required' : 'nullable'), 'string', 'max:2000'],
        ], [
            'title.required' => __('crm.board.title_required'),
            'amount.required' => __('crm.board.amount_required'),
            'amount.gt' => __('crm.board.amount_positive'),
            'cancelled_reason.required' => __('crm.board.cancel_reason_required'),
        ]);

        $deal->update(Arr::only($data, ['title', 'amount', 'payment_status', 'note']));

        // Through the same door a drag used to go through, and the row's own
        // quick-select still does — the guard that keeps the automation from
        // overruling this only works if every hand move stamps the deal here.
        if (! empty($data['stage']) && $data['stage'] !== $deal->stage) {
            $this->board->move($deal, $data['stage'], byOwner: true, cancelledReason: $data['cancelled_reason'] ?? null);
        } elseif ($deal->stage === Deal::STAGE_CANCELLED && array_key_exists('cancelled_reason', $data)) {
            $deal->update(['cancelled_reason' => $data['cancelled_reason']]);
        }

        if (! empty($data['deal_date'])) $this->board->setDealDate($deal, $data['deal_date']);

        // Only when the field was actually sent — the edit form only ever
        // includes it while the deal sits in Оплата, and a save from any
        // other state must not be read as "clear the flag".
        if ($request->has('closed')) {
            $this->board->setClosed($deal, $request->boolean('closed'));
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
     * @return array{stage: string, q: ?string, sort: string, dir: string}
     */
    private function filters(Request $request): array
    {
        $stage = $request->query('stage');
        $sort = $request->query('sort');

        return [
            'stage' => in_array($stage, array_merge(['all', self::LEADS_BUCKET], Deal::allStages()), true) ? $stage : 'all',
            'q' => trim((string) $request->query('q')) ?: null,
            'sort' => in_array($sort, DealBoard::SORTS, true) ? $sort : 'date',
            'dir' => $request->query('dir') === 'asc' ? 'asc' : 'desc',
            'page' => max(1, (int) $request->query('page', 1)),
        ];
    }
}
