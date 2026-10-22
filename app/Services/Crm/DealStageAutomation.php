<?php

namespace App\Services\Crm;

use App\Models\Conversation;
use App\Models\CrmStage;
use App\Models\CrmWorkspace;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Lead;
use App\Services\Haggling\HagglingService;

class DealStageAutomation
{
    public function __construct(protected DealBoard $board) {}

    public function conversationStarted(Conversation $conversation, Customer $customer): ?Deal
    {
        if ($this->dealFor($conversation) !== null) {
            return null;
        }

        $team = $conversation->agent?->team_id;
        $initial = CrmStage::initialFor($customer->user, $team);

        $deal = new Deal;

        $deal->forceFill([
            'user_id' => $customer->user_id,
            'crm_workspace_id' => CrmWorkspace::defaultFor($customer->user, $team)->id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation->id,
            'agent_id' => $conversation->agent_id,

            'title' => $customer->displayName(),
            'crm_stage_id' => $initial->id,
            'stage' => $initial->name,
            'stage_role' => $initial->role,
            'position' => 0,
            'stage_changed_at' => now(),
        ])->save();

        return $deal;
    }

    public function negotiationOpened(Lead $lead): ?Deal
    {
        if ($lead->type !== HagglingService::TYPE) {
            return null;
        }

        $user = $lead->customer?->user;

        $team = $lead->agent?->team_id ?? $lead->customer?->team_id;
        $intermediates = $user !== null ? CrmStage::intermediatesFor($user, $team) : collect();

        if ($intermediates->count() !== 1) {
            return null;
        }

        return $this->fromLead($lead, $intermediates->first());
    }

    public function orderPlaced(Lead $lead): ?Deal
    {
        if ($lead->type === HagglingService::TYPE) {
            return null;
        }

        $deal = $this->openDealFor($lead);

        if ($deal !== null) {

            $isPlaceholderTitle = blank($deal->title) || $deal->title === $deal->customer?->displayName();
            $deal->forceFill([
                'lead_id' => $deal->lead_id ?? $lead->id,
                'title' => $isPlaceholderTitle ? $lead->items : $deal->title,
            ])->save();
        }

        return $deal;
    }

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

    private function fromLead(Lead $lead, CrmStage $stage): ?Deal
    {
        $deal = $this->openDealFor($lead);

        return $deal === null ? null : $this->advance($deal, $stage);
    }

    private function openDealFor(Lead $lead): ?Deal
    {
        return $lead->conversation !== null
            ? $this->dealFor($lead->conversation)
            : Deal::where('customer_id', $lead->customer_id)->open()->latest('id')->first();
    }

    private function dealFor(?Conversation $conversation): ?Deal
    {
        if ($conversation === null) {
            return null;
        }

        return Deal::where('conversation_id', $conversation->id)->open()->latest('id')->first();
    }

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
