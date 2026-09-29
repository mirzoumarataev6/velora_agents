<?php

namespace App\Services\Crm;

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
        $query = $this->filteredDeals($user, $filters)
            ->with([
                'customer:id,name,phone,status,tags',
                'conversation:id,channel_id,last_message_at',
                'conversation.channel:id,type',
                'tasks' => fn ($query) => $query->open()
                    ->orderByRaw('due_at is null')->orderBy('due_at')->orderBy('id')->limit(1),
            ]);

        $page = max(1, (int) request()->query('page', 1));
        $results = $query->paginate(self::PER_PAGE, ['*'], 'page', $page);
        if ($page > $results->lastPage()) {
            $results = $query->paginate(self::PER_PAGE, ['*'], 'page', max(1, $results->lastPage()));
        }

        return $results->withQueryString()->through(fn (Deal $deal) => $this->card($deal));
    }

    /** One row, as both the Blade view and the poll consume it. */
    public function card(Deal $deal): array
    {
        // What last happened on this deal — the customer's own channel if
        // there is one, and the deal's own last edit otherwise (a manually
        // opened deal with nobody writing in yet).
        $lastActivity = $deal->conversation?->last_message_at ?? $deal->updated_at;
        $nextTask = $deal->relationLoaded('tasks')
            ? $deal->tasks->first()
            : $deal->tasks()->open()->orderByRaw('due_at is null')->orderBy('due_at')->orderBy('id')->first();

        return [
            'id' => $deal->id,
            'stage' => $deal->stage,
            'title' => $deal->title,
            'customer_id' => $deal->customer_id,
            'customer_name' => $deal->customer?->name ?: __('crm.customers.no_name'),
            // Folded into the deal panel now rather than reached through a
            // separate customer directory — this is the whole point of the
            // merge: opening a deal is enough to see who they are.
            'customer_phone' => $deal->customer?->phone,
            'customer_status' => $deal->customer?->status,
            'customer_tags' => $deal->customer?->tags ?? [],
            'channel' => $deal->conversation?->channel?->type,
            // Formatted for display...
            'amount' => $deal->amount !== null ? Lead::formatPrice($deal->amount) : null,
            // ...and again as a plain decimal string, for an edit form to
            // populate a number input with — Lead::formatPrice's thousands
            // separator would make "2 500" an invalid number.
            'amount_raw' => $deal->amount !== null ? (string) $deal->amount : null,
            'payment_status' => $deal->payment_status,
            'note' => $deal->note,
            'cancelled_reason' => $deal->cancelled_reason,
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

    /** Business-level pipeline figures for the CRM landing page. */
    public function overview(User $user): array
    {
        $counts = Deal::ownedBy($user)
            ->selectRaw('stage, COUNT(*) as total, COALESCE(SUM(amount), 0) as value')
            ->groupBy('stage')->get()->keyBy('stage');

        $cancelReasons = Deal::ownedBy($user)
            ->where('stage', Deal::STAGE_CANCELLED)
            ->selectRaw("COALESCE(NULLIF(cancelled_reason, ''), 'unspecified') as reason, COUNT(*) as total")
            ->groupBy('reason')->orderByDesc('total')->limit(5)->get();

        $paid = (int) ($counts['payment']->total ?? 0);
        $lost = (int) ($counts[Deal::STAGE_CANCELLED]->total ?? 0);

        return [
            'counts' => collect(Deal::allStages())->mapWithKeys(fn ($stage) => [$stage => (int) ($counts[$stage]->total ?? 0)])->all(),
            'paid_value' => (float) ($counts['payment']->value ?? 0),
            'conversion' => $paid + $lost > 0 ? round(($paid / ($paid + $lost)) * 100) : null,
            'cancel_reasons' => $cancelReasons->map(fn ($row) => ['reason' => $row->reason, 'total' => (int) $row->total])->all(),
        ];
    }

    /** Build exactly the same scoped filter query for list and summary totals. */
    private function filteredDeals(User $user, array $filters): Builder
    {
        $query = Deal::ownedBy($user);

        if (($filters['stage'] ?? 'all') !== 'all') {
            $query->where('stage', $filters['stage']);
        }

        if (filled($filters['q'] ?? null)) {
            $term = trim($filters['q']);
            $query->where(fn ($search) => $search
                ->where('title', 'like', "%{$term}%")
                ->orWhereHas('customer', fn ($customer) => $customer->search($term)));
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
     * `position` no longer orders anything visible now that the board is a
     * list rather than columns a card sits within, but every caller still
     * writes it and nothing reads staleness into it, so it is left alone
     * rather than spending a migration on a column that costs nothing as is.
     */
    public function move(Deal $deal, string $stage, ?int $position = null, bool $byOwner = false, ?string $cancelledReason = null): Deal
    {
        $changedStage = $deal->stage !== $stage;
        // A deal can only be Закрыто while it sits in Оплата — leaving that
        // stage for any reason (including back into it later) means the
        // owner has to say so again, rather than a stale flag from a
        // previous visit to Оплата quietly surviving the trip.
        $leavingPayment = $changedStage && $stage !== 'payment';

        DB::transaction(function () use ($deal, $stage, $position, $byOwner, $changedStage, $leavingPayment, $cancelledReason) {
            $target = $position ?? 0;

            Deal::where('user_id', $deal->user_id)
                ->where('stage', $stage)
                ->whereKeyNot($deal->id)
                ->where('position', '>=', $target)
                ->increment('position');

            $fill = array_filter([
                'stage' => $stage,
                'position' => $target,
                'stage_changed_at' => $changedStage ? now() : $deal->stage_changed_at,
                'stage_set_by_owner_at' => $byOwner ? now() : $deal->stage_set_by_owner_at,
                'cancelled_reason' => $stage === Deal::STAGE_CANCELLED ? $cancelledReason : null,
            ], fn ($value) => $value !== null);

            if ($changedStage && $stage !== Deal::STAGE_CANCELLED) {
                $fill['cancelled_reason'] = null;
            }

            if ($stage === 'payment' && $changedStage) {
                // This stage is the owner's confirmation that money arrived.
                $fill['payment_status'] = 'paid';
            }

            // Not part of the filtered array above: null is exactly the
            // value being assigned here, and array_filter would drop it.
            if ($leavingPayment) {
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
     */
    public function setClosed(Deal $deal, bool $closed): Deal
    {
        if (! $deal->canBeClosed()) {
            return $deal;
        }

        $deal->forceFill(['closed_at' => $closed ? ($deal->closed_at ?? now()) : null])->save();

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
