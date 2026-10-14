<?php

namespace App\Services\Crm;

use App\Models\CrmStage;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Reads and rearranges the deal pipeline.
 *
 * Separate from the controller because DealStageAutomation moves a deal
 * through exactly the same door the owner's own stage change does — if the
 * two had their own copies of "what moving a deal means", the forward-only
 * rule and the stage_set_by_owner_at guard could drift depending on which
 * path happened to run.
 */
class DealBoard
{
    /** What the list can be sorted by: when the deal was logged, or its size. */
    public const SORTS = ['date', 'amount'];

    public const PER_PAGE = 25;

    /**
     * Search, stage and sort run in SQL; only the requested page reaches the
     * browser, so no record silently disappears after an arbitrary row cap.
     */
    public function list(User $user, array $filters = []): LengthAwarePaginator
    {
        // The list is customer-first: repeat orders and channel threads belong
        // in the customer's profile, while one representative deal keeps the
        // existing quick stage/edit actions useful from the pipeline.
        $query = $this->filteredDeals($user, $filters);
        $direction = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sortByAmount = ($filters['sort'] ?? 'date') === 'amount';
        $groups = (clone $query)
            ->reorder()
            ->selectRaw('customer_id, COUNT(*) as matching_deal_count')
            ->groupBy('customer_id')
            ->when($sortByAmount, fn ($builder) => $builder->orderByRaw("MAX(amount) {$direction}"))
            ->when(! $sortByAmount, fn ($builder) => $builder->orderByRaw("MAX(COALESCE(deal_date, DATE(created_at))) {$direction}"))
            ->orderBy('customer_id', $direction)
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, (int) request()->query('page', 1)))
            ->withQueryString();

        if ($groups->isEmpty() && $groups->currentPage() > 1) {
            $groups = (clone $query)->reorder()
                ->selectRaw('customer_id, COUNT(*) as matching_deal_count')
                ->groupBy('customer_id')
                ->when($sortByAmount, fn ($builder) => $builder->orderByRaw("MAX(amount) {$direction}"))
                ->when(! $sortByAmount, fn ($builder) => $builder->orderByRaw("MAX(COALESCE(deal_date, DATE(created_at))) {$direction}"))
                ->orderBy('customer_id', $direction)
                ->paginate(self::PER_PAGE, ['*'], 'page', max(1, $groups->lastPage()))
                ->withQueryString();
        }

        $rows = $groups->getCollection();
        $customerIds = $rows->pluck('customer_id')->all();
        // Which deal actually earned each customer's spot in the list: the
        // highest-amount deal when sorted by amount, the newest otherwise.
        // Deliberately NOT part of the grouped query above — MAX(id) there
        // would pick the newest deal regardless of sort, so a board sorted by
        // amount could show the right position but the wrong deal's number
        // on the card. Resolved in PHP, the same way purchaseCounts and
        // customers below are: a second, bounded query over this page's
        // customers only.
        $representativeByCustomer = $this->representativeDealIds($user, $filters, $customerIds, $sortByAmount);
        $representativeIds = array_values($representativeByCustomer);
        $deals = Deal::ownedBy($user)->whereIn('id', $representativeIds)
            ->with([
                'customer:id,name,phone,status,tags',
                'conversation:id,channel_id,last_message_at',
                'conversation.channel:id,type',
                'tasks' => fn ($query) => $query->visibleInCrm($user)->open()
                    ->orderByRaw('due_at is null')->orderBy('due_at')->orderBy('id')->limit(1),
            ])->get()->keyBy('id');

        $allStageFilters = $filters;
        $allStageFilters['stage'] = 'all';
        $purchaseCounts = $customerIds === [] ? collect() : $this->filteredDeals($user, $allStageFilters)
            // filteredDeals also carries the table's requested sort; grouped
            // aggregate queries must drop it for MySQL ONLY_FULL_GROUP_BY.
            ->reorder()
            ->whereIn('customer_id', $customerIds)
            ->tap(fn ($query) => self::paidInWonStage()($query))
            ->selectRaw('customer_id, COUNT(*) as total')
            ->groupBy('customer_id')->pluck('total', 'customer_id');
        $customers = $customerIds === [] ? collect() : Customer::ownedBy($user)
            ->whereIn('id', $customerIds)->withCount('conversations')->get(['id'])->keyBy('id');

        $groups->setCollection($rows->map(function ($row) use ($deals, $purchaseCounts, $customers, $representativeByCustomer, $user) {
            $deal = $deals->get($representativeByCustomer[$row->customer_id] ?? null);
            if (! $deal) {
                return null;
            }

            $purchaseCount = (int) ($purchaseCounts[$row->customer_id] ?? 0);
            $conversationCount = (int) ($customers[$row->customer_id]->conversations_count ?? 0);

            return $this->card(
                $deal,
                $purchaseCount,
                $conversationCount,
                trans_choice('crm.board.customer_purchases_count', $purchaseCount, ['count' => $purchaseCount]),
                trans_choice('crm.board.customer_conversations_count', $conversationCount, ['count' => $conversationCount]),
                $user,
            );
        })->filter()->values());

        return $groups;
    }

    /**
     * Which deal represents each customer's row on this page — the one that
     * actually produced the value the group is sorted by, not just whichever
     * happens to have the highest id.
     *
     * Sorted by amount: the highest-amount deal among this customer's
     * matching rows (ties broken by the newest, since that is still the most
     * likely to be what the owner means by "their" deal at that amount).
     * Any other sort: the newest matching deal, same as before this method
     * existed — `MAX(id)` was always right for that case, it was only wrong
     * once amount-sorting also started relying on it.
     *
     * A second, bounded query rather than a correlated subquery on the
     * grouped one: `filteredDeals()` applies `ownedBy()`'s `deals.`-qualified
     * column, and self-joining the same table to pick a per-group extreme
     * needs an aliased copy of it — resolving it here in PHP, over at most
     * this page's customers, is simpler and no slower for a page this size.
     *
     * @param  int[]  $customerIds
     * @return array<int, int> customer_id => deal_id
     */
    private function representativeDealIds(User $user, array $filters, array $customerIds, bool $sortByAmount): array
    {
        if ($customerIds === []) {
            return [];
        }

        return $this->filteredDeals($user, $filters)->reorder()
            ->whereIn('customer_id', $customerIds)
            ->get(['id', 'customer_id', 'amount'])
            ->groupBy('customer_id')
            ->map(function ($deals) use ($sortByAmount) {
                return $deals->reduce(function (?Deal $best, Deal $deal) use ($sortByAmount) {
                    if ($best === null) {
                        return $deal;
                    }

                    if ($sortByAmount) {
                        $bestAmount = $best->amount !== null ? (float) $best->amount : -INF;
                        $dealAmount = $deal->amount !== null ? (float) $deal->amount : -INF;

                        if ($dealAmount !== $bestAmount) {
                            return $dealAmount > $bestAmount ? $deal : $best;
                        }
                    }

                    // Newest wins: either the sort itself (not by amount), or
                    // the tie-break when two deals share the top amount.
                    return $deal->id > $best->id ? $deal : $best;
                })->id;
            })
            ->all();
    }

    /** One row, as both the Blade view and the poll consume it. */
    public function card(
        Deal $deal,
        int $customerPurchaseCount = 0,
        int $customerConversationCount = 0,
        ?string $customerPurchaseCountLabel = null,
        ?string $customerConversationCountLabel = null,
        ?User $viewer = null,
    ): array {
        // What last happened on this deal — the customer's own channel if
        // there is one, and the deal's own last edit otherwise (a manually
        // opened deal with nobody writing in yet).
        $lastActivity = $deal->conversation?->last_message_at ?? $deal->updated_at;
        $nextTask = $deal->relationLoaded('tasks')
            ? $deal->tasks->first()
            : ($viewer?->activeTeamId() !== null
                ? $deal->tasks()->visibleInCrm($viewer)->open()->orderByRaw('due_at is null')->orderBy('due_at')->orderBy('id')->first()
                : null);

        return [
            'id' => $deal->id,
            'crm_workspace_id' => $deal->crm_workspace_id,
            'stage' => $deal->stage,
            'stage_role' => $deal->stage_role,
            'title' => $deal->title,
            'customer_id' => $deal->customer_id,
            'customer_name' => $deal->customer?->name ?: __('crm.customers.no_name'),
            // Folded into the deal panel now rather than reached through a
            // separate customer directory — this is the whole point of the
            // merge: opening a deal is enough to see who they are.
            'customer_phone' => $deal->customer?->phone,
            'customer_status' => $deal->customer?->status,
            'customer_tags' => $deal->customer?->tags ?? [],
            'customer_purchase_count' => $customerPurchaseCount,
            'customer_conversation_count' => $customerConversationCount,
            'customer_purchase_count_label' => $customerPurchaseCountLabel,
            'customer_conversation_count_label' => $customerConversationCountLabel,
            'channel' => $deal->conversation?->channel?->type,
            // Formatted for display...
            'amount' => $deal->amount !== null ? Lead::formatPrice($deal->amount) : null,
            // ...and again as a plain decimal string, for an edit form to
            // populate a number input with — Lead::formatPrice's thousands
            // separator would make "2 500" an invalid number.
            'amount_raw' => $deal->amount !== null ? (string) $deal->amount : null,
            'payment_status' => $deal->payment_status,
            'sales_origin' => $deal->sales_origin ?: 'social',
            'note' => $deal->note,
            'tags' => $deal->tags ?? [],
            'cancelled_reason' => $deal->cancelled_reason,
            'won_reason' => $deal->won_reason,
            'conversation_id' => $deal->conversation_id,
            // Rendered server-side: "3 дня" has to be in the owner's language,
            // and the client has no localisation of its own for durations.
            'in_stage' => $deal->timeInStage(),
            'last_activity' => $lastActivity?->diffForHumans(),
            'deal_date' => ($deal->deal_date ?? $deal->created_at)?->format('Y-m-d'),
            'deal_date_label' => ($deal->deal_date ?? $deal->created_at)?->translatedFormat('d MMM Y'),
            // Full precision, for the client's own "by date" sort — deal_date
            // above only has day granularity, which would tie two same-day
            // deals and leave their order to chance.
            'sort_date' => ($deal->deal_date ?? $deal->created_at)?->toIso8601String(),
            // "Закрыто" — only meaningful, and only ever settable, on Оплата.
            'is_closed' => $deal->closed_at !== null,
            'can_be_closed' => $deal->canBeClosed(),
            'closed_label' => $deal->closed_at?->translatedFormat('d MMM Y'),
            'next_task' => $nextTask ? [
                'title' => $nextTask->title,
                'due_at' => $nextTask->due_at?->translatedFormat('d M, H:i'),
                'overdue' => $nextTask->isOverdue(),
            ] : null,
        ];
    }

    /**
     * People in the book with no deal at all — not a stage, a customer.
     *
     * This is the one gap a deal-centric list has: nearly every real
     * customer gets a deal automatically the moment their first message
     * creates a conversation, but a handful do not — historical data
     * imported before deals existed, or a deal the owner deleted outright.
     * Without somewhere to see them they simply vanish from the CRM, so this
     * is that somewhere: not a rival screen, a filtered slice of the same one.
     *
     * Paginated and searchable like the deals index, without a hidden cap.
     */
    public function leadsWithoutDeals(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = Customer::ownedBy($user)
            ->whereDoesntHave('deals')
            ->when(filled($filters['q'] ?? null), fn ($query) => $query->search($filters['q']))
            ->with(['conversations:id,customer_id,channel_id', 'conversations.channel:id,type'])
            ->orderByDesc('last_contact_at');

        $page = max(1, (int) request()->query('page', 1));
        $results = $query->paginate(self::PER_PAGE, ['*'], 'page', $page);
        if ($page > $results->lastPage()) {
            $results = $query->paginate(self::PER_PAGE, ['*'], 'page', max(1, $results->lastPage()));
        }

        return $results->withQueryString()->through(fn (Customer $customer) => [
            'id' => $customer->id,
            'name' => $customer->displayName(),
            'phone' => $customer->phone,
            'status' => $customer->status,
            'channel' => $customer->channelTypes()[0] ?? null,
            'last_activity' => $customer->last_contact_at?->diffForHumans(),
        ]);
    }

    /** Count the "Без сделки" tab without loading its customer rows on other tabs. */
    public function leadsWithoutDealsCount(User $user, array $filters = []): int
    {
        return Customer::ownedBy($user)
            ->whereDoesntHave('deals')
            ->when(filled($filters['q'] ?? null), fn ($query) => $query->search($filters['q']))
            ->count();
    }

    /**
     * The "paid" definition used everywhere revenue or a purchase gets
     * counted: payment_status alone is not enough — it is independently
     * settable from the stage (see DealController::update()'s guard against
     * exactly that) — so a deal can read payment_status='paid' without ever
     * having reached the WON-role stage, and counting those would inflate
     * every purchase/revenue figure the CRM reports. One definition, used by
     * DealBoard::list()/overview(), CustomerController's buyer/repeat
     * filters, the deep-linked deal's own count in DealController, and the
     * customer profile's purchase summary — all five used to carry their
     * own copy of this same query.
     */
    public static function paidInWonStage(): \Closure
    {
        return fn ($query) => $query->where('payment_status', 'paid')->where('stage_role', 'won');
    }

    /** Business-level pipeline figures for the CRM landing page. */
    public function overview(User $user, array $filters = []): array
    {
        $scopedDeals = Deal::ownedBy($user)
            ->when(! empty($filters['workspace_id']), fn ($query) => $query->where('crm_workspace_id', $filters['workspace_id']))
            ->when(in_array($filters['origin'] ?? 'all', Deal::SALES_ORIGINS, true), fn ($query) => $query->where('sales_origin', $filters['origin']));

        $stages = CrmStage::defaultsFor($user, $user->activeTeamId());

        $countsByStage = (clone $scopedDeals)
            ->selectRaw('crm_stage_id, COUNT(*) as total, COALESCE(SUM(amount), 0) as value')
            ->groupBy('crm_stage_id')->get()->keyBy('crm_stage_id');

        $countsByRole = (clone $scopedDeals)
            ->selectRaw("COALESCE(stage_role, '') as stage_role, COUNT(*) as total")
            ->groupBy('stage_role')->get()->keyBy('stage_role');

        $cancelReasons = (clone $scopedDeals)
            ->where('stage_role', 'lost')
            ->selectRaw("COALESCE(NULLIF(cancelled_reason, ''), 'unspecified') as reason, COUNT(*) as total")
            ->groupBy('reason')->orderByDesc('total')->limit(5)->get();

        $wonStage = $stages->firstWhere('role', 'won');
        $paid = (int) ($countsByRole['won']->total ?? 0);
        $lost = (int) ($countsByRole['lost']->total ?? 0);

        $originCounts = Deal::ownedBy($user)
            ->when(! empty($filters['workspace_id']), fn ($query) => $query->where('crm_workspace_id', $filters['workspace_id']))
            ->selectRaw('sales_origin, COUNT(*) as total')
            ->groupBy('sales_origin')->pluck('total', 'sales_origin');

        return [
            'counts' => $stages->mapWithKeys(fn (CrmStage $stage) => [$stage->name => (int) ($countsByStage[$stage->id]->total ?? 0)])->all(),
            'paid_value' => (float) ($wonStage ? ($countsByStage[$wonStage->id]->value ?? 0) : 0),
            'conversion' => $paid + $lost > 0 ? round(($paid / ($paid + $lost)) * 100) : null,
            'cancel_reasons' => $cancelReasons->map(fn ($row) => ['reason' => $row->reason, 'total' => (int) $row->total])->all(),
            'origin_counts' => collect(Deal::SALES_ORIGINS)->mapWithKeys(fn ($origin) => [$origin => (int) ($originCounts[$origin] ?? 0)])->all(),
        ];
    }

    /**
     * Build exactly the same scoped filter query for list, summary totals,
     * and CSV export (DealController::export()) — one definition of "what
     * the board is currently filtered to" for all three.
     */
    public function filteredDeals(User $user, array $filters): Builder
    {
        $query = Deal::ownedBy($user);

        if (($filters['stage'] ?? 'all') === 'in_progress') {
            // Initial + every intermediate — intermediates carry a NULL
            // role, which `whereNotIn` would silently exclude (SQL's NOT IN
            // is unknown, not true, against a NULL), so it is named here
            // explicitly rather than negated.
            $query->where(fn ($q) => $q->whereNull('stage_role')->orWhere('stage_role', 'initial'));
        } elseif (($filters['stage'] ?? 'all') === 'resolved') {
            $query->whereIn('stage_role', ['won', 'lost']);
        } elseif (($filters['stage'] ?? 'all') !== 'all') {
            $query->where('stage', $filters['stage']);
        }

        if (in_array($filters['origin'] ?? 'all', Deal::SALES_ORIGINS, true)) {
            $query->where('sales_origin', $filters['origin']);
        }

        if (! empty($filters['workspace_id'])) {
            $query->where('crm_workspace_id', $filters['workspace_id']);
        }

        if (filled($filters['q'] ?? null)) {
            $term = trim($filters['q']);
            $query->where(fn ($search) => $search
                ->where('title', 'like', "%{$term}%")
                ->orWhereHas('customer', fn ($customer) => $customer->search($term)));
        }

        // A plain LIKE over the serialized JSON column, same trick
        // Customer::scopeSearch() uses for its own tags — a real
        // whereJsonContains() match would need the exact stored casing, and
        // nothing here claims tags are ever normalised to one case.
        if (filled($filters['tag'] ?? null)) {
            $query->where('tags', 'like', '%'.trim($filters['tag']).'%');
        }

        $direction = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        if (($filters['sort'] ?? 'date') === 'amount') {
            return $query->orderBy('amount', $direction)->orderByDesc('id');
        }

        return $query->orderByRaw("COALESCE(deal_date, DATE(created_at)) {$direction}")->orderBy('created_at', $direction)->orderByDesc('id');
    }

    /**
     * Move a deal to a new stage — from the list's quick-select, from the
     * edit form, or from DealStageAutomation. `byOwner` is what makes the
     * automation safe: a hand move stamps the deal, and DealStageAutomation
     * refuses to overrule a stamp newer than its own signal.
     *
     * The one place `deals.stage` / `deals.stage_role` / `deals.crm_stage_id`
     * are written together, in the same transaction — see CrmStageSyncTest
     * for the invariant this keeps: the three can never disagree, because
     * nothing outside this method (and DealStageAutomation, which always
     * routes through it) ever assigns any of them.
     *
     * `position` no longer orders anything visible now that the board is a
     * list rather than columns a card sits within, but every caller still
     * writes it and nothing reads staleness into it, so it is left alone
     * rather than spending a migration on a column that costs nothing as is.
     */
    public function move(Deal $deal, CrmStage $stage, ?int $position = null, bool $byOwner = false, ?string $cancelledReason = null): Deal
    {
        $changedStage = $deal->crm_stage_id !== $stage->id;
        // A deal can only be Закрыто while it sits in the WON stage —
        // leaving that stage for any reason (including back into it later)
        // means the owner has to say so again, rather than a stale flag
        // from a previous visit quietly surviving the trip.
        $leavingWon = $changedStage && $deal->stage_role === 'won' && $stage->role !== 'won';

        DB::transaction(function () use ($deal, $stage, $position, $byOwner, $changedStage, $leavingWon, $cancelledReason) {
            $target = $position ?? 0;

            // Keyed on the DEAL'S OWN team, not user_id or the signed-in
            // caller's active team: this runs from both the UI (the owner
            // moving a card) and DealStageAutomation (off a webhook, no
            // signed-in user at all), and the deal's own team is the one
            // thing both callers already agree on.
            Deal::where('team_id', $deal->team_id)
                ->where('crm_stage_id', $stage->id)
                ->whereKeyNot($deal->id)
                ->where('position', '>=', $target)
                ->increment('position');

            $fill = array_filter([
                'crm_stage_id' => $stage->id,
                'stage' => $stage->name,
                'position' => $target,
                'stage_changed_at' => $changedStage ? now() : $deal->stage_changed_at,
                'stage_set_by_owner_at' => $byOwner ? now() : $deal->stage_set_by_owner_at,
                'cancelled_reason' => $stage->role === 'lost' ? $cancelledReason : null,
            ], fn ($value) => $value !== null);

            // Not filtered above with the rest: null is exactly the value an
            // intermediate (role-less) stage needs written, and array_filter
            // would silently keep whatever role the deal had before instead
            // — precisely the drift CrmStageSyncTest exists to catch.
            $fill['stage_role'] = $stage->role;

            if ($changedStage && $stage->role !== 'lost') {
                $fill['cancelled_reason'] = null;
            }

            if ($stage->role === 'won' && $changedStage) {
                // This stage is the owner's confirmation that money arrived.
                $fill['payment_status'] = 'paid';
            }

            // Not part of the filtered array above: null is exactly the
            // value being assigned here, and array_filter would drop it.
            if ($leavingWon) {
                $fill['closed_at'] = null;
            }

            $deal->forceFill($fill)->save();
        });

        return $deal;
    }

    /**
     * "Закрыто" — the owner attesting a paid deal is fully done. Refused
     * silently rather than validated with an error: the control that offers
     * this is only ever shown on a deal already in Оплата, so reaching this
     * with a deal that is not is a stale click, not a real request.
     *
     * `$wonReason` is the won counterpart to cancelled_reason — set here,
     * the one place a deal is ever actually attested as won, mirroring how
     * move() is the one place cancelled_reason is set. Optional: unlike a
     * loss, a sale needs no excuse, so a blank reason simply keeps whatever
     * was already there rather than being forced blank on every resave.
     * Cleared only when the deal leaves Закрыто, alongside closed_at.
     */
    public function setClosed(Deal $deal, bool $closed, ?string $wonReason = null): Deal
    {
        if (! $deal->canBeClosed()) {
            return $deal;
        }

        $deal->forceFill([
            'closed_at' => $closed ? ($deal->closed_at ?? now()) : null,
            'won_reason' => $closed ? ($wonReason ?: $deal->won_reason) : null,
        ])->save();

        return $deal;
    }

    /**
     * Sets when a deal actually happened, for a sale the owner is logging or
     * editing after the fact.
     *
     * A raw update rather than assigning and saving: an UPDATE never re-stamps
     * created_at on its own the way an INSERT does, so the model's own
     * attribute has to be pushed there explicitly for a backdate to survive.
     */
    public function setDealDate(Deal $deal, string $date): void
    {
        $deal->forceFill(['deal_date' => $date])->save();
    }
}
