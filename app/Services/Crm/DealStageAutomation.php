<?php

namespace App\Services\Crm;

use App\Models\Conversation;
use App\Models\CrmStage;
use App\Models\CrmWorkspace;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Services\Haggling\HagglingService;

/**
 * Moves cards on their own, using signals the system ALREADY produces.
 *
 * WHAT DRIVES WHAT, and nothing else does:
 *
 *   Новый лид   a Conversation row is created (HandleIncomingMessageJob)
 *   Торг        a Lead of type 'haggle' appears — the agent called
 *               request_price_approval, which IS the price negotiation
 *
 * That is the whole list. An order being placed (create_lead) and a price
 * being approved (approved_price set) both still touch the deal — filling in
 * what is being bought, or the number that was agreed — but neither moves
 * the stage any more. Under the old six-stage pipeline they were the
 * strongest signals the system had for "money is now the subject", which is
 * exactly what Оплата used to mean. It no longer does: Оплата now means the
 * money actually arrived, and this system has no way to observe that. An
 * order placed is a customer's intent, not a receipt; an approved price is
 * the owner's willingness to sell at that number, not proof they were paid
 * it. Writing either of them onto Оплата would be the system asserting a
 * payment happened that nobody has confirmed — so it stays manual, and this
 * class only fills in the deal's details while leaving the stage alone.
 *
 * Доставка, Завершено and Отменено stay out of here for the same reason they
 * always were: no real signal for any of them exists, and a lookalike (a
 * "closed" conversation, say) would just be a board full of confident
 * fiction wearing an honest one's clothes.
 *
 * TWO RULES KEEP IT SAFE.
 *
 * Forward only. A signal can pull a card further down the pipeline, never
 * back.
 *
 * Event-driven only. Every method here runs off the event as it happens and
 * never re-derives a stage from history, so a signal cannot fire twice and
 * cannot resurrect a decision the owner has since overruled by hand.
 */
class DealStageAutomation
{
    public function __construct(protected DealBoard $board) {}

    /**
     * A customer has written for the first time on this thread: open a card.
     *
     * One open deal per conversation. A second one would mean the board grows a
     * duplicate card every time an existing customer says hello.
     */
    public function conversationStarted(Conversation $conversation, Customer $customer): ?Deal
    {
        if ($this->dealFor($conversation) !== null) {
            return null;
        }

        // The team this thread's agent belongs to, not current_team_id:
        // this runs off a webhook (ConversationObserver), where
        // current_team_id is whichever team the owner happens to be
        // looking at right now, not necessarily the team this agent
        // belongs to. Null (no agent, or an agent not yet stamped with a
        // team) falls back to exactly today's user_id-scoped behaviour.
        $team = $conversation->agent?->team_id;
        $initial = CrmStage::initialFor($customer->user, $team);

        $deal = new Deal;

        $deal->forceFill([
            'user_id' => $customer->user_id,
            'crm_workspace_id' => CrmWorkspace::defaultFor($customer->user, $team)->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'agent_id' => $conversation->agent_id,
            'crm_stage_id' => $initial->id,
            'stage' => $initial->name,
            'stage_role' => $initial->role,
            'position' => 0,
            'stage_changed_at' => now(),
        ])->save();

        return $deal;
    }

    /**
     * The agent has asked the owner to approve a price — which is precisely
     * what "the customer is haggling" looks like in this system, PROVIDED
     * the owner's pipeline still has exactly one intermediate stage to mean
     * it. The default, un-customized pipeline always does, so this signal
     * keeps moving a card exactly as before for every account that hasn't
     * touched its stages. The moment an owner adds a second intermediate,
     * there is no longer one unambiguous "this is what haggling looks like"
     * stage to advance into — guessing which of several would be exactly
     * the kind of confident fiction this class's own docblock already
     * refuses for Доставка/Завершено/Отменено, so the signal simply stops
     * moving the card (it still reaches orderPlaced()/priceAgreed() for the
     * details that never depended on the stage either way).
     */
    public function negotiationOpened(Lead $lead): ?Deal
    {
        if ($lead->type !== HagglingService::TYPE) {
            return null;
        }

        $user = $lead->customer?->user;
        // Same reasoning as conversationStarted() above: the lead's own
        // agent is the team this actually belongs to, off a webhook
        // (LeadObserver) rather than the owner's current_team_id. Falls
        // back to the customer's own (already-resolved) team_id if the
        // agent relation is somehow missing, and to today's behaviour if
        // neither is available.
        $team = $lead->agent?->team_id ?? $lead->customer?->team_id;
        $intermediates = $user !== null ? CrmStage::intermediatesFor($user, $team) : collect();

        if ($intermediates->count() !== 1) {
            return null;
        }

        return $this->fromLead($lead, $intermediates->first());
    }

    /**
     * An order was recorded. Used to be the strongest signal for "waiting on
     * payment"; now it only tells the deal what is being bought, since
     * placing an order is not proof anything was paid.
     */
    public function orderPlaced(Lead $lead): ?Deal
    {
        if ($lead->type === HagglingService::TYPE) {
            return null;
        }

        $deal = $this->openDealFor($lead);

        if ($deal !== null) {
            $deal->forceFill([
                'lead_id' => $deal->lead_id ?? $lead->id,
                'title' => $deal->title ?: $lead->items,
            ])->save();
        }

        return $deal;
    }

    /**
     * The owner approved a haggled price. That is a number, not a receipt —
     * it says what the deal is worth, which the board should show, but it is
     * the owner agreeing to a price, not confirmation the customer paid it.
     */
    public function priceAgreed(Lead $lead): ?Deal
    {
        if (! $lead->isHaggle() || blank($lead->approved_price)) {
            return null;
        }

        $deal = $this->openDealFor($lead);

        if ($deal !== null && blank($deal->amount)) {
            $deal->forceFill(['amount' => $lead->approved_price])->save();
        }

        return $deal;
    }

    /**
     * The open card a lead belongs to, found through its conversation and
     * failing that through its customer — a lead whose thread was pruned is
     * still that person's business.
     */
    private function fromLead(Lead $lead, CrmStage $stage): ?Deal
    {
        $deal = $this->openDealFor($lead);

        return $deal === null ? null : $this->advance($deal, $stage);
    }

    /** The open deal a lead's conversation or customer already has, if any. */
    private function openDealFor(Lead $lead): ?Deal
    {
        return $lead->conversation !== null
            ? $this->dealFor($lead->conversation)
            : Deal::where('customer_id', $lead->customer_id)->open()->latest('id')->first();
    }

    /** The open card for a thread, if it has one. */
    private function dealFor(?Conversation $conversation): ?Deal
    {
        if ($conversation === null) {
            return null;
        }

        return Deal::where('conversation_id', $conversation->id)->open()->latest('id')->first();
    }

    /**
     * Move a card forward, or leave it exactly where it is.
     *
     * The one place the forward-only rule lives, so no signal can accidentally
     * be written as an unconditional assignment. "Forward" is now the
     * owner's own `crm_stages.position`, not a hardcoded array index — a
     * deal with no stage position on record (shouldn't happen once every
     * write path sets one, but the column is nullable — see the deals
     * migration) is treated as sitting behind everything, same as the old
     * stageIndex()'s -1 did for an unrecognised value.
     */
    private function advance(Deal $deal, CrmStage $stage): ?Deal
    {
        if ($deal->isClosed()) {
            return null;
        }

        $currentPosition = $deal->crmStage?->position ?? -1;

        if (($stage->position ?? -1) <= $currentPosition) {
            return null;
        }

        return $this->board->move($deal, $stage);
    }
}
