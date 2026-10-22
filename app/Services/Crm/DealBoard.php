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

class DealBoard
{
    public const SORTS = ['date', 'amount'];

    public const PER_PAGE = 25;

    public function list(User $user, array $filters = []): LengthAwarePaginator
    {

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

                    return $deal->id > $best->id ? $deal : $best;
                })->id;
            })
            ->all();
    }

    public function card(
        Deal $deal,
        int $customerPurchaseCount = 0,
        int $customerConversationCount = 0,
        ?string $customerPurchaseCountLabel = null,
        ?string $customerConversationCountLabel = null,
        ?User $viewer = null,
    ): array {

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

            'customer_phone' => $deal->customer?->phone,
            'customer_status' => $deal->customer?->status,
            'customer_tags' => $deal->customer?->tags ?? [],
            'customer_purchase_count' => $customerPurchaseCount,
            'customer_conversation_count' => $customerConversationCount,
            'customer_purchase_count_label' => $customerPurchaseCountLabel,
            'customer_conversation_count_label' => $customerConversationCountLabel,
            'channel' => $deal->conversation?->channel?->type,

            'amount' => $deal->amount !== null ? Lead::formatPrice($deal->amount) : null,

            'amount_raw' => $deal->amount !== null ? (string) $deal->amount : null,
            'payment_status' => $deal->payment_status,
            'sales_origin' => $deal->sales_origin ?: 'social',
            'note' => $deal->note,
            'tags' => $deal->tags ?? [],
            'cancelled_reason' => $deal->cancelled_reason,
            'won_reason' => $deal->won_reason,
            'conversation_id' => $deal->conversation_id,

            'in_stage' => $deal->timeInStage(),
            'last_activity' => $lastActivity?->diffForHumans(),
            'deal_date' => ($deal->deal_date ?? $deal->created_at)?->format('Y-m-d'),

            'deal_date_label' => ($deal->deal_date ?? $deal->created_at)?->translatedFormat('d M Y'),

            'sort_date' => ($deal->deal_date ?? $deal->created_at)?->toIso8601String(),

            'is_closed' => $deal->closed_at !== null,
            'can_be_closed' => $deal->canBeClosed(),
            'closed_label' => $deal->closed_at?->translatedFormat('d M Y'),
            'next_task' => $nextTask ? [
                'title' => $nextTask->title,
                'due_at' => $nextTask->due_at?->translatedFormat('d M, H:i'),
                'overdue' => $nextTask->isOverdue(),
            ] : null,
        ];
    }

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

    public function leadsWithoutDealsCount(User $user, array $filters = []): int
    {
        return Customer::ownedBy($user)
            ->whereDoesntHave('deals')
            ->when(filled($filters['q'] ?? null), fn ($query) => $query->search($filters['q']))
            ->count();
    }

    public static function paidInWonStage(): \Closure
    {
        return fn ($query) => $query->where('payment_status', 'paid')->where('stage_role', 'won');
    }

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

    public function filteredDeals(User $user, array $filters): Builder
    {
        $query = Deal::ownedBy($user);

        if (($filters['stage'] ?? 'all') === 'in_progress') {

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

        if (filled($filters['tag'] ?? null)) {
            $query->where('tags', 'like', '%'.trim($filters['tag']).'%');
        }

        $direction = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        if (($filters['sort'] ?? 'date') === 'amount') {
            return $query->orderBy('amount', $direction)->orderByDesc('id');
        }

        return $query->orderByRaw("COALESCE(deal_date, DATE(created_at)) {$direction}")->orderBy('created_at', $direction)->orderByDesc('id');
    }

    public function move(Deal $deal, CrmStage $stage, ?int $position = null, bool $byOwner = false, ?string $cancelledReason = null): Deal
    {
        $changedStage = $deal->crm_stage_id !== $stage->id;

        $leavingWon = $changedStage && $deal->stage_role === 'won' && $stage->role !== 'won';

        DB::transaction(function () use ($deal, $stage, $position, $byOwner, $changedStage, $leavingWon, $cancelledReason) {
            $target = $position ?? 0;

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

            $fill['stage_role'] = $stage->role;

            if ($changedStage && $stage->role !== 'lost') {
                $fill['cancelled_reason'] = null;
            }

            if ($stage->role === 'won' && $changedStage) {

                $fill['payment_status'] = 'paid';
            }

            if ($leavingWon) {
                $fill['closed_at'] = null;
            }

            $deal->forceFill($fill)->save();
        });

        return $deal;
    }

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

    public function setDealDate(Deal $deal, string $date): void
    {
        $deal->forceFill(['deal_date' => $date])->save();
    }
}
