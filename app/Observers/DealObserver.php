<?php

namespace App\Observers;

use App\Models\CrmStage;
use App\Models\Deal;
use App\Models\DealActivity;

class DealObserver
{
    public function created(Deal $deal): void
    {
        $this->record($deal, 'created');
    }

    public function updated(Deal $deal): void
    {
        if ($deal->wasChanged('stage')) {
            // Frozen labels, not a live lookup: a stage can be renamed or
            // deleted later, and this moment in the timeline has to keep
            // reading the way it did when it happened — "moved from Торг to
            // Оплата" must not quietly become "moved from [deleted] to
            // Payment received" the day the owner tidies up their pipeline.
            // `to`/`from` (the raw stage NAME string) are kept alongside the
            // frozen labels/roles for activity rows written before this
            // existed, which still render off the literal string alone.
            $previousStageId = $deal->getPrevious()['crm_stage_id'] ?? null;
            $previousStage = $previousStageId !== null ? CrmStage::find($previousStageId) : null;

            $this->record($deal, 'stage_changed', [
                'from' => $deal->getPrevious()['stage'] ?? null,
                'to' => $deal->stage,
                'from_role' => $previousStage?->role,
                'to_role' => $deal->stage_role,
                'from_label' => $previousStage?->displayName(),
                'to_label' => $deal->crmStage?->displayName(),
                'reason' => $deal->getPrevious()['cancelled_reason'] ?? $deal->cancelled_reason,
            ]);
        }

        // The won counterpart to the cancellation branch above: closing is
        // the owner attesting the sale is done, which deserves its own
        // moment in the timeline the same way cancelling does, rather than
        // falling into the generic "updated" bucket — see DealBoard::setClosed().
        if ($deal->wasChanged('closed_at') && $deal->closed_at !== null) {
            $this->record($deal, 'closed', ['reason' => $deal->won_reason]);
        }

        $changes = [];
        foreach (['title', 'amount', 'payment_status', 'cancelled_reason', 'won_reason'] as $field) {
            if ($field === 'cancelled_reason' && $deal->wasChanged('stage')) {
                continue;
            }
            // Already reported by the 'closed' activity above — recording it
            // again here would say the same thing twice.
            if ($field === 'won_reason' && $deal->wasChanged('closed_at')) {
                continue;
            }
            if ($deal->wasChanged($field)) {
                $changes[$field] = ['from' => $deal->getPrevious()[$field] ?? null, 'to' => $deal->{$field}];
            }
        }

        if ($changes !== []) {
            $this->record($deal, 'updated', ['changes' => $changes]);
        }
    }

    private function record(Deal $deal, string $type, array $data = []): void
    {
        DealActivity::create([
            'user_id' => $deal->user_id,
            // Explicit, not left to StampsTeam's own user_id fallback: a deal
            // opened in the background (DealStageAutomation, off a webhook)
            // already carries the right team via its own agent_id, resolved
            // the moment it was created — reusing that here is correct
            // regardless of which team the owner happens to be looking at
            // right now, which is all current_team_id could tell us instead.
            'team_id' => $deal->team_id,
            'deal_id' => $deal->id,
            'type' => $type,
            'data' => $data ?: null,
        ]);
    }
}
